<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\Vehicle;
use App\Services\WialonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FleetTrackingController extends Controller
{
    public function units(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->units()]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function sync(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => ['synced' => $wialon->syncVehicles()]]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function reportTemplates(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            $selected = json_decode(DB::table('wialon_installation_settings')->where('id', 'installation')->value('resource_ids') ?: '[]', true) ?: [];
            return response()->json(['status' => 'success', 'data' => collect($wialon->reportTemplates())->whereIn('id', $selected)->values()]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function runReport(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before running a report.');
        $data = $request->validate([
            'resource_id' => ['required', 'integer', 'min:1'],
            'template_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'gt:from'],
            'table_index' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:399500'],
        ]);
        abort_if(($data['offset'] ?? 0) > 0 && !isset($data['table_index']), 422, 'A table is required when requesting another report page.');
        abort_if(($data['offset'] ?? 0) % 500 !== 0, 422, 'Report pages must be requested in 500-row increments.');
        $allowedResources = json_decode(DB::table('wialon_installation_settings')->where('id', 'installation')->value('resource_ids') ?: '[]', true) ?: [];
        abort_unless(in_array((int) $data['resource_id'], array_map('intval', $allowedResources), true), 422, 'Choose a report resource enabled in installation GPS settings.');
        abort_if($data['to'] - $data['from'] > 366 * 86400, 422, 'Report intervals cannot exceed one year.');
        try {
            return response()->json([
                'status' => 'success',
                'data' => $wialon->runReport(
                    (int) $vehicle->wialon_unit_id,
                    $data['resource_id'],
                    $data['template_id'],
                    $data['from'],
                    $data['to'],
                    $data['table_index'] ?? null,
                    $data['offset'] ?? 0
                )
            ]);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 422);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function mileage(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before changing its odometer.');
        $data = $request->validate(['mileage_km' => ['required', 'integer', 'min:0', 'max:' . WialonService::MAX_COUNTER_KILOMETERS]]);
        $vehicle->current_mileage = $data['mileage_km'];
        $vehicle->save();
        $message = 'Mileage saved in the portal.';
        $wialonSynced = false;
        try {
            $wialon->setMileage((int) $vehicle->wialon_unit_id, $data['mileage_km']);
            $wialonSynced = true;
        } catch (RuntimeException $error) {
            $message .= ' GPS counter sync failed: ' . $error->getMessage();
        }
        $vehicle->refresh();
        return response()->json(['status' => 'success', 'message' => $message, 'data' => ['mileage_km' => $vehicle->current_mileage, 'wialon_mileage' => $vehicle->wialon_mileage, 'wialon_synced' => $wialonSynced, 'wialon_mileage_sync_pending' => (bool) $vehicle->wialon_mileage_sync_pending]]);
    }

    public function telemetry(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before requesting telemetry.');
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->telemetry((int) $vehicle->wialon_unit_id)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function diagnostics(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before requesting diagnostics.');
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->diagnostics((int) $vehicle->wialon_unit_id)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function engineHours(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before changing engine hours.');
        $data = $request->validate(['engine_hours' => ['required', 'integer', 'min:0', 'max:4294967295']]);
        try {
            $hours = $wialon->setEngineHours((int) $vehicle->wialon_unit_id, (int) $data['engine_hours']);
            return response()->json(['status' => 'success', 'data' => ['engine_hours' => $hours]]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function commands(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before requesting commands.');
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->commands((int) $vehicle->wialon_unit_id)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function executeCommand(Request $request, Vehicle $vehicle, int $commandId, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before executing commands.');
        $data = $request->validate(['parameter' => ['nullable', 'string', 'max:2000']]);
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->executeCommand((int) $vehicle->wialon_unit_id, $commandId, $data['parameter'] ?? null)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function alerts(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a GPS device before requesting alerts.');
        $defaultFrom = now()->subDays(7)->timestamp;
        $data = $request->validate([
            'from' => ['sometimes', 'integer', 'min:1'],
            'to' => ['sometimes', 'integer', 'gt:' . ($request->input('from', $defaultFrom))],
        ]);
        $from = (int) ($data['from'] ?? $defaultFrom);
        $to = (int) ($data['to'] ?? now()->timestamp);
        abort_unless($to > $from, 422, 'Alert end must be after start.');
        abort_if($to - $from > 366 * 86400, 422, 'Alert intervals cannot exceed one year.');
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->alertEvents((int) $vehicle->wialon_unit_id, $from, $to)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function position(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        if (!$vehicle->wialon_unit_id)
            return response()->json(['status' => 'success', 'data' => null]);
        try {
            $unit = collect($wialon->units())->firstWhere('id', (int) $vehicle->wialon_unit_id);
            return response()->json(['status' => 'success', 'data' => $unit]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function index(Request $request, WialonService $wialon): JsonResponse
    {
        $units = [];
        $wialonError = null;
        try {
            $units = $wialon->units();
        } catch (RuntimeException $error) {
            $wialonError = $error->getMessage();
        }
        $vehicles = Vehicle::withInactive()
            ->select('id', 'title', 'license_plate', 'registration_no', 'wialon_unit_id', 'current_mileage', 'is_active', 'availability_status', 'wialon_mileage', 'wialon_mileage_sync_pending', 'wialon_last_message_at', 'wialon_last_synced_at')
            ->orderBy('title')
            ->get();
        $unitById = collect($units)->keyBy(fn(array $unit) => (int) ($unit['id'] ?? 0));
        $vehicles = $vehicles->reject(fn(Vehicle $vehicle) => $wialon->isImportedPlaceholder(
            $vehicle,
            $unitById->get((int) $vehicle->wialon_unit_id, [])
        ))->values();
        return response()->json([
            'status' => 'success',
            'data' => [
                'units' => $units,
                'wialon_error' => $wialonError,
                'vehicles' => $vehicles->map(function (Vehicle $vehicle) use ($unitById) {
                    $unit = $unitById->get((int) $vehicle->wialon_unit_id);
                    return [
                        'id' => $vehicle->id,
                        'title' => $vehicle->title,
                        'license_plate' => $vehicle->license_plate,
                        'registration_no' => $vehicle->registration_no,
                        'wialon_unit_id' => $vehicle->wialon_unit_id,
                        'current_mileage' => $vehicle->current_mileage,
                        'is_active' => (bool) $vehicle->is_active,
                        'availability_status' => $vehicle->availability_status,
                        'wialon_mileage' => $vehicle->wialon_mileage,
                        'wialon_mileage_sync_pending' => (bool) $vehicle->wialon_mileage_sync_pending,
                        'wialon_last_message_at' => $vehicle->wialon_last_message_at,
                        'wialon_last_synced_at' => $vehicle->wialon_last_synced_at,
                        'unit' => $unit
                    ];
                }),
            ]
        ]);
    }

    public function link(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        $unitRules = ['present', 'nullable', 'integer'];
        if ($request->filled('wialon_unit_id')) {
            try {
                $unitIds = collect($wialon->units())->pluck('id')->map(fn($id) => (string) $id)->all();
            } catch (RuntimeException $error) {
                return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
            }
            $unitRules[] = Rule::in($unitIds);
        }
        $data = $request->validate(['wialon_unit_id' => $unitRules]);
        DB::transaction(function () use ($vehicle, $data): void {
            $unitId = $data['wialon_unit_id'] ?? null;
            $unitId = $unitId === null ? null : (int) $unitId;
            if ($unitId) {
                $previousVehicle = Vehicle::withTrashed()->withInactive()
                    ->where('wialon_unit_id', (int) $unitId)
                    ->where('id', '!=', $vehicle->id)
                    ->lockForUpdate()
                    ->first();
                if ($previousVehicle) {
                    if (
                        !$previousVehicle->trashed()
                        && ($previousVehicle->is_active || $previousVehicle->created_user_id || $previousVehicle->updated_user_id)
                    ) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'wialon_unit_id' => ['This GPS device is linked to a saved vehicle record. Finish that vehicle setup before moving the device.'],
                        ]);
                    }
                    $previousVehicle->wialon_unit_id = null;
                    $previousVehicle->save();
                    if (!$previousVehicle->trashed()) {
                        $previousVehicle->delete();
                    }
                }
            }

            if ((int) $vehicle->wialon_unit_id !== (int) $unitId) {
                $vehicle->wialon_unique_id = null;
                $vehicle->wialon_hw_type_id = null;
                $vehicle->wialon_mileage = null;
                $vehicle->wialon_last_message_at = null;
                $vehicle->wialon_last_synced_at = null;
                $vehicle->wialon_mileage_sync_pending = false;
                $vehicle->wialon_mileage_sync_requested_at = null;
            }
            $vehicle->wialon_unit_id = $unitId;
            $vehicle->save();
        });
        $syncError = null;
        if ($vehicle->wialon_unit_id) {
            try {
                $wialon->syncVehicles();
                $vehicle->refresh();
            } catch (RuntimeException $error) {
                $syncError = $error->getMessage();
            }
        }
        return response()->json(['status' => 'success', 'data' => [
            'wialon_unit_id' => $vehicle->wialon_unit_id,
            'sync_error' => $syncError,
        ]]);
    }

    public function messages(Request $request, int $unitId, WialonService $wialon): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'integer', 'min:1'],
            'to' => ['required', 'integer', 'gt:from'],
            'offset' => ['sometimes', 'integer', 'min:0', 'max:9900'],
        ]);
        abort_if($data['to'] - $data['from'] > 366 * 86400, 422, 'Message intervals cannot exceed one year.');
        $vehicle = Vehicle::withInactive()->where('wialon_unit_id', $unitId)->firstOrFail();
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->messages( $unitId, $data['from'], $data['to'], $data['offset'] ?? 0)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function settings(): JsonResponse
    {
        $row = $this->integrationSettingsRow();
        return response()->json(['status' => 'success', 'data' => ['configured' => (bool) $row, 'enabled' => (bool) ($row->enabled ?? false), 'base_url' => $row->base_url ?? 'https://hst-api.wialon.com', 'resource_ids' => json_decode($row->resource_ids ?? '[]', true) ?: [], 'group_ids' => json_decode($row->group_ids ?? '[]', true) ?: [], 'unit_ids' => []]]);
    }

    public function catalog(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => ['resources' => $wialon->resources(), 'groups' => $wialon->unitGroups(), 'units' => $wialon->units(true)]]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function addUnitToGroup(Request $request, int $groupId, WialonService $wialon): JsonResponse
    {
        $data = $request->validate(['unit_id' => ['required', 'integer', 'min:1']]);
        try {
            $membership = $wialon->addUnitToGroup($groupId, (int) $data['unit_id']);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }

        $syncError = null;
        $synced = 0;
        try {
            $synced = $wialon->syncVehicles();
        } catch (RuntimeException $error) {
            $syncError = $error->getMessage();
        }

        return response()->json(['status' => 'success', 'data' => [
            ...$membership,
            'synced' => $synced,
            'sync_error' => $syncError,
        ]]);
    }

    public function removeUnitFromGroup(Request $request, int $groupId, int $unitId, WialonService $wialon): JsonResponse
    {
        try {
            $membership = $wialon->removeUnitFromGroup($groupId, $unitId);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }

        $syncError = null;
        $synced = 0;
        try {
            $synced = $wialon->syncVehicles();
        } catch (RuntimeException $error) {
            $syncError = $error->getMessage();
        }

        return response()->json(['status' => 'success', 'data' => [
            ...$membership,
            'synced' => $synced,
            'sync_error' => $syncError,
        ]]);
    }

    public function saveSettings(Request $request, WialonService $wialon): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'min:10', 'max:10000'],
            'resource_ids' => ['array'],
            'resource_ids.*' => ['integer', 'min:1'],
            'group_ids' => ['array'],
            'group_ids.*' => ['integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
        ]);
        $existing = $this->integrationSettingsRow();
        abort_unless($existing || $data['token'] ?? false, 422, 'Enter the GPS service token to connect this installation.');
        $token = $data['token'] ?? decrypt($existing->token);
        try {
            DB::transaction(function () use ($existing, $token, $data, $wialon): void {
                $values = ['token' => encrypt($token), 'base_url' => 'https://hst-api.wialon.com', 'resource_ids' => json_encode(array_values(array_unique($data['resource_ids'] ?? []))), 'group_ids' => json_encode(array_values(array_unique($data['group_ids'] ?? []))), 'unit_ids' => '[]', 'enabled' => $data['enabled'], 'updated_at' => now()];
                if ($existing) {
                    DB::table('wialon_installation_settings')->where('id', 'installation')->update($values);
                } else {
                    DB::table('wialon_installation_settings')->insert($values + ['id' => 'installation', 'created_at' => now()]);
                }
                if ($data['enabled']) {
                    $wialon->validateSelections( $data['resource_ids'] ?? [], $data['group_ids'] ?? []);
                }
            });
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => 'GPS service connection failed; previous settings were kept: ' . $error->getMessage()], 503);
        }
        $synced = 0;
        $syncError = null;
        if ($data['enabled']) {
            try {
                $synced = $wialon->syncVehicles();
            } catch (RuntimeException $error) {
                $syncError = $error->getMessage();
            }
        }
        return response()->json(['status' => 'success', 'data' => ['configured' => true, 'enabled' => (bool) $data['enabled'], 'synced' => $synced, 'sync_error' => $syncError]]);
    }

    private function integrationSettingsRow(): ?object
    {
        return DB::table('wialon_installation_settings')->where('id', 'installation')->first();
    }
}
