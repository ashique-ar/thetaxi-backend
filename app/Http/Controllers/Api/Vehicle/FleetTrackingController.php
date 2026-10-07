<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\Vehicle;
use App\Services\WialonService;
use App\Services\SingleCompanyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FleetTrackingController extends Controller
{
    public function units(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->units($this->companyId($request))]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function sync(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => ['synced' => $wialon->syncVehicles($this->companyId($request))]]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function reportTemplates(Request $request, WialonService $wialon): JsonResponse
    {
        try {
            $companyId = $this->companyId($request);
            $selected = json_decode(DB::table('wialon_integrations')->where('company_id', $companyId)->value('resource_ids') ?: '[]', true) ?: [];
            return response()->json(['status' => 'success', 'data' => collect($wialon->reportTemplates($companyId))->whereIn('id', $selected)->values()]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function runReport(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless((string) $vehicle->company_id === $this->companyId($request), 403);
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a Wialon unit before running a report.');
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
        $allowedResources = json_decode(DB::table('wialon_integrations')->where('company_id', $vehicle->company_id)->value('resource_ids') ?: '[]', true) ?: [];
        abort_unless(in_array((int) $data['resource_id'], array_map('intval', $allowedResources), true), 422, 'Choose a resource enabled in company Wialon settings.');
        abort_if($data['to'] - $data['from'] > 366 * 86400, 422, 'Report intervals cannot exceed one year.');
        try {
            return response()->json([
                'status' => 'success',
                'data' => $wialon->runReport(
                    (string) $vehicle->company_id,
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
        abort_unless((string) $vehicle->company_id === $this->companyId($request), 403);
        abort_unless($vehicle->wialon_unit_id, 422, 'Link a Wialon unit before changing its odometer.');
        $data = $request->validate(['mileage_km' => ['required', 'integer', 'min:0', 'max:' . WialonService::MAX_COUNTER_KILOMETERS]]);
        $vehicle->current_mileage = $data['mileage_km'];
        $vehicle->save();
        $message = 'Mileage saved in TheTaxi.';
        $wialonSynced = false;
        try {
            $mileage = $wialon->setMileage((string) $vehicle->company_id, (int) $vehicle->wialon_unit_id, $data['mileage_km']);
            $vehicle->wialon_mileage = $mileage;
            $vehicle->save();
            $wialonSynced = true;
        } catch (RuntimeException $error) {
            $message .= ' Wialon counter sync failed: ' . $error->getMessage();
        }
        return response()->json(['status' => 'success', 'message' => $message, 'data' => ['mileage_km' => $vehicle->current_mileage, 'wialon_mileage' => $vehicle->wialon_mileage, 'wialon_synced' => $wialonSynced]]);
    }

    public function position(Request $request, Vehicle $vehicle, WialonService $wialon): JsonResponse
    {
        abort_unless((string) $vehicle->company_id === $this->companyId($request), 403);
        if (!$vehicle->wialon_unit_id)
            return response()->json(['status' => 'success', 'data' => null]);
        try {
            $unit = collect($wialon->units((string) $vehicle->company_id))->firstWhere('id', (int) $vehicle->wialon_unit_id);
            return response()->json(['status' => 'success', 'data' => $unit]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function index(Request $request, WialonService $wialon): JsonResponse
    {
        $companyId = $this->companyId($request);
        $units = [];
        $wialonError = null;
        try {
            $units = $wialon->units($companyId);
        } catch (RuntimeException $error) {
            $wialonError = $error->getMessage();
        }
        $vehicles = Vehicle::withInactive()
            ->where(fn($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->select('id', 'title', 'license_plate', 'registration_no', 'wialon_unit_id', 'current_mileage', 'is_active', 'availability_status', 'wialon_mileage', 'wialon_last_message_at', 'wialon_last_synced_at')
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
        $companyId = $this->companyId($request);
        abort_unless(!$vehicle->company_id || (string) $vehicle->company_id === $companyId, 403);
        $unitRules = ['present', 'nullable', 'integer'];
        if ($request->filled('wialon_unit_id')) {
            try {
                $unitIds = collect($wialon->units($companyId))->pluck('id')->map(fn($id) => (string) $id)->all();
            } catch (RuntimeException $error) {
                return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
            }
            $unitRules[] = Rule::in($unitIds);
        }
        $data = $request->validate(['wialon_unit_id' => $unitRules]);
        DB::transaction(function () use ($vehicle, $companyId, $data): void {
            $unitId = $data['wialon_unit_id'] ?? null;
            if ($unitId) {
                $previousVehicle = Vehicle::withTrashed()->withInactive()
                    ->where('company_id', $companyId)
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
                            'wialon_unit_id' => ['This Wialon unit is linked to a saved vehicle record. Finish that vehicle setup before moving the device.'],
                        ]);
                    }
                    $previousVehicle->wialon_unit_id = null;
                    $previousVehicle->save();
                    if (!$previousVehicle->trashed()) {
                        $previousVehicle->delete();
                    }
                }
            }

            $vehicle->wialon_unit_id = $unitId;
            if (!$unitId) {
                $vehicle->wialon_unique_id = null;
                $vehicle->wialon_hw_type_id = null;
                $vehicle->wialon_mileage = null;
                $vehicle->wialon_last_message_at = null;
                $vehicle->wialon_last_synced_at = null;
            }
            $vehicle->company_id ??= $companyId;
            $vehicle->save();
        });
        $syncError = null;
        if ($vehicle->wialon_unit_id) {
            try {
                $wialon->syncVehicles($companyId);
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
        $vehicle = Vehicle::withInactive()->where('wialon_unit_id', $unitId)->where('company_id', $this->companyId($request))->firstOrFail();
        try {
            return response()->json(['status' => 'success', 'data' => $wialon->messages((string) $vehicle->company_id, $unitId, $data['from'], $data['to'], $data['offset'] ?? 0)]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function settings(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $row = DB::table('wialon_integrations')->where('company_id', $companyId)->first();
        return response()->json(['status' => 'success', 'data' => ['configured' => (bool) $row, 'enabled' => (bool) ($row->enabled ?? false), 'base_url' => $row->base_url ?? 'https://hst-api.wialon.com', 'resource_ids' => json_decode($row->resource_ids ?? '[]', true) ?: [], 'group_ids' => json_decode($row->group_ids ?? '[]', true) ?: [], 'unit_ids' => []]]);
    }

    public function catalog(Request $request, WialonService $wialon): JsonResponse
    {
        $companyId = $this->companyId($request);
        try {
            return response()->json(['status' => 'success', 'data' => ['resources' => $wialon->resources($companyId), 'groups' => $wialon->unitGroups($companyId), 'units' => $wialon->units($companyId, true)]]);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }
    }

    public function addUnitToGroup(Request $request, int $groupId, WialonService $wialon): JsonResponse
    {
        $data = $request->validate(['unit_id' => ['required', 'integer', 'min:1']]);
        $companyId = $this->companyId($request);
        try {
            $membership = $wialon->addUnitToGroup($companyId, $groupId, (int) $data['unit_id']);
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => $error->getMessage()], 503);
        }

        $syncError = null;
        $synced = 0;
        try {
            $synced = $wialon->syncVehicles($companyId);
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
        $companyId = $this->companyId($request);
        $existing = DB::table('wialon_integrations')->where('company_id', $companyId)->first();
        abort_unless($existing || $data['token'] ?? false, 422, 'Enter the Wialon token to connect this company.');
        $token = $data['token'] ?? decrypt($existing->token);
        try {
            DB::transaction(function () use ($companyId, $existing, $token, $data, $wialon): void {
                DB::table('wialon_integrations')->updateOrInsert(['company_id' => $companyId], ['id' => $existing->id ?? (string) Str::uuid(), 'token' => encrypt($token), 'base_url' => 'https://hst-api.wialon.com', 'resource_ids' => json_encode(array_values(array_unique($data['resource_ids'] ?? []))), 'group_ids' => json_encode(array_values(array_unique($data['group_ids'] ?? []))), 'unit_ids' => '[]', 'enabled' => $data['enabled'], 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()]);
                if ($data['enabled']) {
                    $wialon->validateSelections($companyId, $data['resource_ids'] ?? [], $data['group_ids'] ?? []);
                }
            });
        } catch (RuntimeException $error) {
            return response()->json(['status' => 'error', 'message' => 'Wialon connection failed; previous settings were kept: ' . $error->getMessage()], 503);
        }
        $synced = 0;
        $syncError = null;
        if ($data['enabled']) {
            try {
                $synced = $wialon->syncVehicles($companyId);
            } catch (RuntimeException $error) {
                $syncError = $error->getMessage();
            }
        }
        return response()->json(['status' => 'success', 'data' => ['configured' => true, 'enabled' => (bool) $data['enabled'], 'synced' => $synced, 'sync_error' => $syncError]]);
    }

    private function companyId(Request $request): string
    {
        if ($request->header('X-Active-Context-Type') === 'internal') {
            $company = app(SingleCompanyScope::class)->activeDefaultCompany();
            abort_unless($company, 409, 'An active default company is required to manage fleet settings from the Internal Portal.');

            return (string) $company->id;
        }

        $companyId = app(\App\Services\StaffAccessService::class)->currentActorStaff($request->user())->company_id;
        abort_unless($companyId, 403, 'The active Staff context has no company assigned.');

        return (string) $companyId;
    }
}
