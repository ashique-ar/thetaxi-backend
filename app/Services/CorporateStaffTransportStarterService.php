<?php

namespace App\Services;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateTransportProgram;
use App\Models\Corporate\CorporateTransportRoute;
use App\Models\Corporate\CorporateTransportShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

class CorporateStaffTransportStarterService
{
    private const UUID_NAMESPACE = '7de677f4-2bd9-4db4-90b1-57276c0fe596';

    /**
     * Create missing starter records without changing anything the company edited.
     *
     * @return array{program_created: bool, shifts_created: int, routes_created: int}
     */
    public function provision(Corporate $corporate): array
    {
        return DB::transaction(function () use ($corporate): array {
            $programId = $this->id($corporate->id, 'program:standard');
            $starters = CorporateTransportProgram::withTrashed()
                ->where('corporate_id', $corporate->id)
                ->orderBy('created_at')
                ->get()
                ->filter(fn ($candidate) => (bool) data_get($candidate->settings, 'starter_template'));
            $program = CorporateTransportProgram::withTrashed()->find($programId) ?? $starters->first();
            $programCreated = false;

            if (!$program) {
                $template = config('corporate_staff_transport.program');
                $program = CorporateTransportProgram::create([
                    'corporate_id' => $corporate->id,
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'status' => 'draft',
                    'timezone' => config('corporate_staff_transport.timezone'),
                    'default_opt_mode' => $template['default_opt_mode'],
                    'cutoff_minutes_before' => config('corporate_staff_transport.cutoff_minutes_before'),
                    'settings' => ['starter_template' => true, 'template_version' => 2, 'excluded_dates' => []],
                    'is_active' => true,
                ]);
                $programCreated = true;
            }

            $this->removeUnusedDuplicateStarters($program, $starters);

            if ($program->trashed()) {
                return ['program_created' => false, 'shifts_created' => 0, 'routes_created' => 0];
            }

            $shiftsCreated = 0;
            $settings = $program->settings ?? [];
            $recordIds = is_array($settings['starter_records'] ?? null) ? $settings['starter_records'] : [];
            foreach (config('corporate_staff_transport.shifts', []) as $template) {
                $id = $this->id($corporate->id, 'shift:'.$template['key']);
                $existing = !empty($recordIds['shifts'][$template['key']])
                    ? CorporateTransportShift::withTrashed()->find($recordIds['shifts'][$template['key']])
                    : null;
                $existing ??= CorporateTransportShift::withTrashed()->where('program_id', $program->id)->where('name', $template['name'])->first();
                $existing ??= CorporateTransportShift::withTrashed()->find($id);
                if (!$existing) {
                    $existing = CorporateTransportShift::create([
                        'program_id' => $program->id,
                        'name' => $template['name'],
                        'pickup_time' => $template['pickup_time'],
                        'dropoff_time' => $template['dropoff_time'],
                        'operating_days' => $template['days'],
                        'cutoff_minutes_before' => config('corporate_staff_transport.cutoff_minutes_before'),
                        'is_active' => true,
                    ]);
                    $shiftsCreated++;
                }
                $recordIds['shifts'][$template['key']] = $existing->id;
            }

            $routesCreated = 0;
            foreach (config('corporate_staff_transport.routes', []) as $template) {
                $id = $this->id($corporate->id, 'route:'.$template['key']);
                $existing = !empty($recordIds['routes'][$template['key']])
                    ? CorporateTransportRoute::withInactive()->withTrashed()->find($recordIds['routes'][$template['key']])
                    : null;
                $matchingRoutes = CorporateTransportRoute::withInactive()->withTrashed()
                    ->where('program_id', $program->id)->where('name', $template['name'])->orderBy('created_at')->get();
                $existing ??= $matchingRoutes->first();
                $existing ??= CorporateTransportRoute::withInactive()->withTrashed()->find($id);
                if (!$existing) {
                    $existing = CorporateTransportRoute::create([
                        'program_id' => $program->id,
                        'name' => $template['name'],
                        'direction' => $template['direction'],
                        'capacity' => 10,
                        'is_active' => true,
                    ]);
                    $routesCreated++;
                }
                $this->removeUnusedDuplicateRoutes($existing, $matchingRoutes);
                if ((int) ($settings['template_version'] ?? 1) < 2 && !$existing->trashed()) {
                    $existing->updateQuietly(['is_active' => true]);
                }
                $recordIds['routes'][$template['key']] = $existing->id;
            }

            $settings['starter_template'] = true;
            $settings['template_version'] = 2;
            $settings['starter_records'] = $recordIds;
            $program->settings = $settings;
            $program->saveQuietly();

            return [
                'program_created' => $programCreated,
                'shifts_created' => $shiftsCreated,
                'routes_created' => $routesCreated,
            ];
        });
    }

    private function id(string $corporateId, string $record): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, $corporateId.':'.$record)->toString();
    }

    private function removeUnusedDuplicateStarters(CorporateTransportProgram $keep, $starters): void
    {
        foreach ($starters as $duplicate) {
            if ((string) $duplicate->id === (string) $keep->id || $duplicate->trashed()) {
                continue;
            }

            $hasRoster = Schema::hasTable('corporate_transport_participations')
                && DB::table('corporate_transport_participations')->where('program_id', $duplicate->id)->exists();
            $hasMembers = Schema::hasTable('corporate_transport_route_members') && DB::table('corporate_transport_route_members')
                ->whereIn('route_id', CorporateTransportRoute::withInactive()->withTrashed()->where('program_id', $duplicate->id)->pluck('id'))
                ->exists();
            if ($hasRoster || $hasMembers) {
                continue;
            }

            CorporateTransportRoute::withInactive()->withTrashed()->where('program_id', $duplicate->id)->forceDelete();
            CorporateTransportShift::withTrashed()->where('program_id', $duplicate->id)->forceDelete();
            $duplicate->forceDelete();
        }
    }

    private function removeUnusedDuplicateRoutes(CorporateTransportRoute $keep, $routes): void
    {
        foreach ($routes as $duplicate) {
            if ((string) $duplicate->id === (string) $keep->id || $duplicate->trashed()) {
                continue;
            }
            $hasMembers = Schema::hasTable('corporate_transport_route_members')
                && DB::table('corporate_transport_route_members')->where('route_id', $duplicate->id)->exists();
            $hasRoster = Schema::hasTable('corporate_transport_participations')
                && DB::table('corporate_transport_participations')->where('route_id', $duplicate->id)->exists();
            if (!$hasMembers && !$hasRoster) {
                $duplicate->forceDelete();
            }
        }
    }
}
