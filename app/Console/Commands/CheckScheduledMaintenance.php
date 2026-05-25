<?php

namespace App\Console\Commands;

use App\Models\Vehicle\Vehicle;
use App\Models\Website\WebsiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckScheduledMaintenance extends Command
{
    protected $signature   = 'maintenance:check-scheduled {--dry-run : Preview actions without persisting}';
    protected $description = 'Mark overdue maintenance schedules as due and block affected vehicles';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today  = now()->toDateString();

        // Fetch scheduled maintenance windows whose start date has passed
        $overdue = DB::table('vehicle_maintenance_schedules')
            ->where('status', 'scheduled')
            ->where('scheduled_date', '<=', $today)
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue maintenance schedules found.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d overdue maintenance schedule(s).', $overdue->count()));

        $rows = [];
        foreach ($overdue as $schedule) {
            $vehicle = Vehicle::find($schedule->vehicle_id);
            $plate   = $vehicle?->license_plate ?? $vehicle?->plate_number ?? $schedule->vehicle_id;

            $rows[] = [
                $schedule->id,
                $plate,
                $schedule->type ?? 'routine',
                $schedule->scheduled_date,
                $dryRun ? 'skipped (dry-run)' : 'updated',
            ];

            if ($dryRun) {
                continue;
            }

            DB::table('vehicle_maintenance_schedules')
                ->where('id', $schedule->id)
                ->update([
                    'status'     => 'due',
                    'updated_at' => now(),
                ]);

            // Block vehicle if not already blocked for maintenance
            if ($vehicle && $vehicle->availability_status !== 'unavailable_maintenance') {
                DB::table('vehicles')
                    ->where('id', $vehicle->id)
                    ->update([
                        'availability_status' => 'unavailable_maintenance',
                        'updated_at'          => now(),
                    ]);
            }

            Log::info('Maintenance schedule marked due by scheduler', [
                'schedule_id' => $schedule->id,
                'vehicle_id'  => $schedule->vehicle_id,
                'type'        => $schedule->type,
                'due_date'    => $schedule->scheduled_date,
            ]);
        }

        $this->table(
            ['Schedule ID', 'Vehicle', 'Type', 'Due Date', 'Action'],
            $rows
        );

        if (!$dryRun) {
            $this->notifyMaintenanceTeam($overdue);
        }

        return self::SUCCESS;
    }

    private function notifyMaintenanceTeam(\Illuminate\Support\Collection $schedules): void
    {
        $email = WebsiteSetting::getValue('maintenance_team_email');
        if (empty($email)) {
            return;
        }

        $lines = $schedules->map(function ($s) {
            $plate = DB::table('vehicles')->where('id', $s->vehicle_id)->value('license_plate') ?? $s->vehicle_id;
            return "- [{$plate}] " . ucfirst($s->type ?? 'routine') . " due on {$s->scheduled_date}";
        })->implode("\n");

        $body = "The following maintenance schedules are now due:\n\n{$lines}\n\n"
            . "Please schedule the affected vehicles for servicing.\n";

        try {
            Mail::raw($body, function ($message) use ($email, $schedules) {
                $message->to($email)
                        ->subject(sprintf('[Maintenance Due] %d vehicle(s) require servicing', $schedules->count()));
            });
        } catch (\Throwable $e) {
            Log::error('Failed to send scheduled-maintenance notification', ['error' => $e->getMessage()]);
        }
    }
}
