<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WialonService
{
    public const MAX_COUNTER_KILOMETERS = 4294967;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://hst-api.wialon.com';
    }

    public function units(string $companyId, bool $includeUnselected = false): array
    {
        $integration = $includeUnselected ? null : $this->integration($companyId);
        return $this->withSession($companyId, function (string $sid) use ($includeUnselected, $integration) {
            $units = $this->searchUnits($sid);
            if ($includeUnselected) return $this->portalUnits($units);
            $groups = $integration->group_ids ? $this->searchUnitGroups($sid) : [];
            $selected = $this->unitIdsFromGroups($groups, $integration->group_ids);
            return $this->portalUnits(array_values(array_filter($units, fn ($unit) => in_array((int) ($unit['id'] ?? 0), $selected, true))));
        });
    }

    private function portalUnits(array $units): array
    {
        return array_values(array_map(function (array $unit): array {
            $safe = array_intersect_key($unit, array_flip(['id', 'nm']));
            $netConnection = data_get($unit, 'item.netconn', $unit['netconn'] ?? null);
            if ($netConnection !== null) {
                $safe['netconn'] = filter_var($netConnection, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            $mileageKm = $this->trackerMileageKm($unit);
            if ($mileageKm !== null) $safe['mileage_km'] = $mileageKm;
            $engineHours = data_get($unit, 'cneh', data_get($unit, 'counters.cneh'));
            if (is_numeric($engineHours)) $safe['engine_hours'] = (float) $engineHours;
            if (isset($unit['pos']) && is_array($unit['pos'])) {
                $safe['pos'] = array_intersect_key($unit['pos'], array_flip(['t', 'x', 'y', 's']));
                if (is_numeric($safe['pos']['s'] ?? null)) {
                    $safe['pos']['s'] = round((float) $safe['pos']['s'] * ($this->usesMiles($unit) ? 1.609344 : 1), 1);
                }
            }
            return $safe;
        }, $units));
    }

    private function trackerMileageKm(array $unit): ?float
    {
        $kilometers = data_get($unit, 'mileage_km', data_get($unit, 'cnm_km', data_get($unit, 'counters.cnm_km')));
        if (is_numeric($kilometers)) return round((float) $kilometers, 2);

        // Wialon Hosting returns cnm at the unit root in km or miles, selected by mu.
        $counter = data_get($unit, 'cnm', data_get($unit, 'counters.cnm'));
        if (!is_numeric($counter)) return null;

        return round((float) $counter * ($this->usesMiles($unit) ? 1.609344 : 1), 2);
    }

    private function usesMiles(array $unit): bool
    {
        return in_array((int) ($unit['mu'] ?? 0), [1, 2], true);
    }

    public function syncVehicles(string $companyId): int
    {
        $synced = 0;
        $integration = $this->integration($companyId);
        $portalMileageAtStart = \App\Models\Vehicle\Vehicle::withInactive()
            ->where('company_id', $companyId)
            ->whereNotNull('wialon_unit_id')
            ->pluck('current_mileage', 'wialon_unit_id')
            ->all();
        $catalog = $this->withSession($companyId, function (string $sid) use ($integration, $companyId) {
            $groups = $integration->group_ids ? $this->searchUnitGroups($sid) : [];
            $units = $this->searchUnits($sid);
            $selectedUnitIds = $this->unitIdsFromGroups($groups, $integration->group_ids);
            if ($selectedUnitIds) {
                $pendingVehicles = \App\Models\Vehicle\Vehicle::withInactive()
                    ->where('company_id', $companyId)
                    ->where('wialon_mileage_sync_pending', true)
                    ->whereNotNull('current_mileage')
                    ->whereIn('wialon_unit_id', $selectedUnitIds)
                    ->get(['id', 'wialon_unit_id', 'current_mileage']);

                foreach ($pendingVehicles as $pendingVehicle) {
                    $requestedMileage = (int) $pendingVehicle->current_mileage;
                    try {
                        $confirmedMileage = $this->writeMileageCounter(
                            $sid,
                            (int) $pendingVehicle->wialon_unit_id,
                            $requestedMileage,
                        );
                        $currentVehicle = \App\Models\Vehicle\Vehicle::withInactive()
                            ->where('company_id', $companyId)
                            ->where('wialon_unit_id', $pendingVehicle->wialon_unit_id)
                            ->where('wialon_mileage_sync_pending', true)
                            ->first();
                        if ($currentVehicle && (int) $currentVehicle->current_mileage === $requestedMileage) {
                            $currentVehicle->forceFill([
                                'wialon_mileage' => $confirmedMileage,
                            ])->save();
                        }
                    } catch (RuntimeException $error) {
                        Log::warning('Pending GPS mileage correction could not be retried', [
                            'company_id' => $companyId,
                            'vehicle_id' => $pendingVehicle->id,
                            'unit_id' => $pendingVehicle->wialon_unit_id,
                            'error' => $error->getMessage(),
                        ]);
                    }
                }
            }

            return ['units' => $units, 'groups' => $groups];
        });
        $selectedUnitIds = $this->unitIdsFromGroups($catalog['groups'], $integration->group_ids);
        $units = array_values(array_filter($catalog['units'], fn ($unit) => in_array((int) ($unit['id'] ?? 0), $selectedUnitIds, true)));
        foreach ($units as $unit) {
            if (empty($unit['id'])) continue;
            $unitId = (int) $unit['id'];
            $mileage = $this->trackerMileageKm($unit);
            $updated = DB::transaction(function () use ($companyId, $unit, $unitId, $mileage, $portalMileageAtStart): bool {
                $vehicle = \App\Models\Vehicle\Vehicle::withInactive()->withTrashed()
                    ->where('company_id', $companyId)
                    ->where('wialon_unit_id', $unitId)
                    ->lockForUpdate()
                    ->first();
                if (!$vehicle || $vehicle->trashed() || $this->isImportedPlaceholder($vehicle, $unit)) return false;

                if ($vehicle->company_id && (string) $vehicle->company_id !== $companyId) return false;
                $previousTrackerMileage = $vehicle->wialon_mileage;
                $vehicle->company_id ??= $companyId;
                $vehicle->wialon_unit_id = $unitId;
                $vehicle->wialon_unique_id = $unit['uid'] ?? $vehicle->wialon_unique_id;
                $vehicle->wialon_hw_type_id = $unit['hw'] ?? $vehicle->wialon_hw_type_id;
                $vehicle->wialon_mileage = is_numeric($mileage) ? $mileage : $vehicle->wialon_mileage;
                $vehicle->wialon_last_message_at = data_get($unit, 'pos.t') ? now()->setTimestamp((int) $unit['pos']['t']) : $vehicle->wialon_last_message_at;
                $vehicle->wialon_last_synced_at = now();

                $hasMileageSnapshot = array_key_exists($unitId, $portalMileageAtStart);
                $mileageAtStart = $hasMileageSnapshot && $portalMileageAtStart[$unitId] !== null
                    ? (int) $portalMileageAtStart[$unitId]
                    : null;
                if (is_numeric($mileage) && $vehicle->wialon_mileage_sync_pending) {
                    $trackerMileage = (int) round($mileage);
                    $reportedAt = (int) data_get($unit, 'pos.t', 0);
                    $counterAdvancedAfterEdit = is_numeric($previousTrackerMileage)
                        && $trackerMileage > (int) round((float) $previousTrackerMileage)
                        && $vehicle->wialon_mileage_sync_requested_at
                        && $reportedAt > $vehicle->wialon_mileage_sync_requested_at->getTimestamp();
                    if ($vehicle->current_mileage !== null
                        && ($trackerMileage === $vehicle->current_mileage
                            || ($counterAdvancedAfterEdit && $trackerMileage >= $vehicle->current_mileage))) {
                        $vehicle->wialon_mileage_sync_pending = false;
                        $vehicle->wialon_mileage_sync_requested_at = null;
                    }
                }
                if (is_numeric($mileage) && !$vehicle->wialon_mileage_sync_pending
                    && $hasMileageSnapshot && $vehicle->current_mileage === $mileageAtStart) {
                    $trackerMileage = (int) round($mileage);
                    if ($vehicle->current_mileage === null || $trackerMileage >= $vehicle->current_mileage) {
                        $vehicle->initial_mileage ??= $trackerMileage;
                        $vehicle->current_mileage = $trackerMileage;
                    }
                }

                $vehicle->save();
                return true;
            });
            if ($updated) $synced++;
        }
        return $synced;
    }

    public function isImportedPlaceholder(\App\Models\Vehicle\Vehicle $vehicle, array $unit): bool
    {
        return !empty($unit['id'])
            && (int) $vehicle->wialon_unit_id === (int) $unit['id']
            && !$vehicle->is_active
            && !$vehicle->license_plate
            && !$vehicle->registration_no
            && !$vehicle->created_user_id
            && !$vehicle->updated_user_id
            && $vehicle->availability_status === \App\Enums\VehicleAvailabilityStatus::UNAVAILABLE_OFFLINE->value
            && mb_strtolower(trim((string) $vehicle->title)) === mb_strtolower(trim((string) ($unit['nm'] ?? '')));
    }

    public function setMileage(string $companyId, int $unitId, int $mileageKm): int
    {
        if ($mileageKm < 0 || $mileageKm > self::MAX_COUNTER_KILOMETERS) {
            throw new RuntimeException('The GPS service accepts mileage counters from 0 to ' . self::MAX_COUNTER_KILOMETERS . ' km.');
        }
        $vehicle = \App\Models\Vehicle\Vehicle::withInactive()
            ->where('company_id', $companyId)
            ->where('wialon_unit_id', $unitId)
            ->first();
        $vehicle?->forceFill([
            'wialon_mileage_sync_pending' => true,
            'wialon_mileage_sync_requested_at' => now(),
        ])->save();
        $this->assertSelectedUnit($companyId, $unitId);

        $confirmedMileage = $this->withSession(
            $companyId,
            fn (string $sid) => $this->writeMileageCounter($sid, $unitId, $mileageKm),
        );
        DB::transaction(function () use ($companyId, $unitId, $mileageKm, $confirmedMileage): void {
            $currentVehicle = \App\Models\Vehicle\Vehicle::withInactive()
                ->where('company_id', $companyId)
                ->where('wialon_unit_id', $unitId)
                ->lockForUpdate()
                ->first();
            if (!$currentVehicle) return;

            if ((int) $currentVehicle->current_mileage === $mileageKm) {
                $currentVehicle->wialon_mileage = $confirmedMileage;
            } else {
                $currentVehicle->wialon_mileage_sync_pending = true;
                $currentVehicle->wialon_mileage_sync_requested_at = now();
            }
            $currentVehicle->save();
        });

        return $confirmedMileage;
    }

    private function writeMileageCounter(string $sid, int $unitId, int $mileageKm): int
    {
        if ($mileageKm < 0 || $mileageKm > self::MAX_COUNTER_KILOMETERS) {
            throw new RuntimeException('The GPS service accepts mileage counters from 0 to ' . self::MAX_COUNTER_KILOMETERS . ' km.');
        }

        $result = $this->call($sid, 'unit/update_mileage_counter', [
            'itemId' => $unitId,
            'newValue' => $mileageKm,
        ]);

        $confirmedMileage = filter_var($result['cnm'] ?? null, FILTER_VALIDATE_INT);
        if (
            $confirmedMileage === false
            || $confirmedMileage < 0
            || $confirmedMileage > self::MAX_COUNTER_KILOMETERS
        ) {
            throw new RuntimeException('The GPS service did not confirm the mileage counter update.');
        }

        return $confirmedMileage;
    }

    public function addUnitToGroup(string $companyId, int $groupId, int $unitId): array
    {
        return $this->updateUnitGroupMembership($companyId, $groupId, $unitId, true);
    }

    public function removeUnitFromGroup(string $companyId, int $groupId, int $unitId): array
    {
        return $this->updateUnitGroupMembership($companyId, $groupId, $unitId, false);
    }

    private function updateUnitGroupMembership(string $companyId, int $groupId, int $unitId, bool $add): array
    {
        $integration = $this->integration($companyId);
        if (!in_array($groupId, array_map('intval', $integration->group_ids), true)) {
            throw ValidationException::withMessages(['group_id' => ['Save this GPS group in company settings before managing its devices.']]);
        }
        if ($groupId < 1 || $unitId < 1) {
            throw ValidationException::withMessages(['unit_id' => ['Choose a valid GPS group and device.']]);
        }

        return $this->withSession($companyId, function (string $sid) use ($groupId, $unitId, $add): array {
            $groups = $this->searchUnitGroups($sid);
            $group = collect($groups)->first(fn (array $item) => (int) ($item['id'] ?? 0) === $groupId);
            if (!$group) throw new RuntimeException('The GPS group is no longer available to this service account.');
            $unitExists = collect($this->searchUnits($sid))->contains(fn (array $unit) => (int) ($unit['id'] ?? 0) === $unitId);
            if (!$unitExists) throw new RuntimeException('The GPS device is no longer available to this service account.');

            $members = array_values(array_unique(array_map('intval', $group['u'] ?? [])));
            $currentlyMember = in_array($unitId, $members, true);
            if ($currentlyMember !== $add) {
                $members = $add
                    ? [...$members, $unitId]
                    : array_values(array_diff($members, [$unitId]));
                $updated = $this->call($sid, 'unit_group/update_units', ['itemId' => $groupId, 'units' => $members]);
                $members = array_values(array_unique(array_map('intval', $updated['u'] ?? [])));
                if (in_array($unitId, $members, true) !== $add) {
                    throw new RuntimeException('The GPS service did not confirm the requested group membership change.');
                }
            }
            return ['group_id' => $groupId, 'unit_ids' => $members, 'is_member' => $add];
        });
    }

    public function reportTemplates(string $companyId): array
    {
        $selected = array_map('intval', $this->integration($companyId)->resource_ids);
        return array_values(array_filter($this->withSession($companyId, fn (string $sid) => $this->searchReportResources($sid)), fn ($resource) => in_array((int) $resource['id'], $selected, true)));
    }

    public function runReport(string $companyId, int $unitId, int $resourceId, int $templateId, int $from, int $to, ?int $tableIndex = null, int $offset = 0): array
    {
        $this->assertSelectedUnit($companyId, $unitId);
        if (!in_array($resourceId, array_map('intval', $this->integration($companyId)->resource_ids), true)) {
            throw new RuntimeException('The selected GPS report resource is not enabled for this company.');
        }
        return $this->withSession($companyId, function (string $sid) use ($unitId, $resourceId, $templateId, $from, $to, $tableIndex, $offset) {
            $resources = $this->searchReportResources($sid);
            $resource = collect($resources)->firstWhere('id', $resourceId);
            if (!$resource || !collect($resource['templates'])->contains(fn ($template) => (int) $template['id'] === $templateId)) {
                throw new RuntimeException('The selected GPS report template is unavailable.');
            }

            $result = $this->call($sid, 'report/exec_report', [
                'reportResourceId' => $resourceId,
                'reportTemplateId' => $templateId,
                'reportObjectId' => $unitId,
                'reportObjectSecId' => 0,
                'interval' => ['from' => $from, 'to' => $to, 'flags' => 0],
            ]);
            $tables = data_get($result, 'reportResult.tables', []);
            try {
                if ($tableIndex !== null && !array_key_exists($tableIndex, $tables)) {
                    throw new \InvalidArgumentException('The selected GPS report table is unavailable.');
                }
                foreach ($tables as $index => &$table) {
                    $count = (int) ($table['rows'] ?? 0);
                    $start = $tableIndex === null || $tableIndex === $index ? $offset : 0;
                    $end = min($start + 499, $count - 1);
                    $shouldFetch = $count > 0 && $start <= $end && ($tableIndex === null || $tableIndex === $index);
                    $table['data'] = $shouldFetch ? $this->call($sid, 'report/get_result_rows', [
                        'tableIndex' => $index, 'indexFrom' => $start, 'indexTo' => $end,
                    ]) : [];
                    $table['offset'] = $start;
                    $table['has_more'] = $start + count($table['data']) < $count;
                }
                unset($table);
                return ['stats' => data_get($result, 'reportResult.stats', []), 'tables' => $tables];
            } finally {
                try {
                    $this->call($sid, 'report/cleanup_result', []);
                } catch (RuntimeException) {
                    // Report data has already been retrieved; the Wialon session also expires.
                }
            }
        });
    }

    private function searchReportResources(string $sid): array
    {
        $items = $this->call($sid, 'core/search_items', [
            'spec' => ['itemsType' => 'avl_resource', 'propName' => 'sys_name', 'propValueMask' => '*', 'sortType' => 'sys_name'],
            'force' => 1, 'flags' => 8193, 'from' => 0, 'to' => 0,
        ])['items'] ?? [];

        return collect($items)->map(fn ($resource) => [
            'id' => (int) $resource['id'],
            'name' => $resource['nm'] ?? 'Resource ' . $resource['id'],
            'templates' => collect($resource['rep'] ?? [])->map(fn ($template) => [
                'id' => (int) $template['id'], 'name' => $template['n'] ?? 'Report ' . $template['id'],
            ])->values()->all(),
        ])->values()->all();
    }

    public function messages(string $companyId, int $unitId, int $from, int $to, int $offset = 0): array
    {
        $this->assertSelectedUnit($companyId, $unitId);
        return $this->withSession($companyId, function (string $sid) use ($unitId, $from, $to, $offset) {
            $loaded = $this->call($sid, 'messages/load_interval', [
                'itemId' => $unitId, 'timeFrom' => $from, 'timeTo' => $to,
                'flags' => 1, 'flagsMask' => 65281, 'loadCount' => 10000,
            ]);
            try {
                $messages = $this->call($sid, 'messages/get_messages', ['indexFrom' => $offset, 'indexTo' => $offset + 100]);
                $total = (int) ($loaded['count'] ?? 0);
                return ['messages' => $messages, 'total' => $total, 'offset' => $offset, 'truncated' => $total > 10000];
            } finally {
                try {
                    $this->call($sid, 'messages/unload', []);
                } catch (RuntimeException) {
                    // The session expires even if the message loader cannot be cleared.
                }
            }
        });
    }

    public function resources(string $companyId): array
    {
        return $this->withSession($companyId, fn (string $sid) => $this->searchReportResources($sid));
    }

    public function unitGroups(string $companyId): array
    {
        return $this->withSession($companyId, fn (string $sid) => $this->searchUnitGroups($sid));
    }

    public function validateSelections(string $companyId, array $resourceIds, array $groupIds): void
    {
        $available = $this->withSession($companyId, fn (string $sid) => [
            'resource_ids' => array_column($this->searchReportResources($sid), 'id'),
            'group_ids' => array_column($this->searchUnitGroups($sid), 'id'),
        ]);
        $errors = [];
        foreach (['resource_ids' => $resourceIds, 'group_ids' => $groupIds] as $field => $selectedIds) {
            $allowedIds = array_map('intval', $available[$field]);
            if (array_diff(array_map('intval', $selectedIds), $allowedIds)) {
                $errors[$field] = ['One or more selected GPS items are unavailable to this company service account. Refresh the GPS catalog and select available items.'];
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function searchUnitGroups(string $sid): array
    {
        return $this->call($sid, 'core/search_items', [
            'spec' => ['itemsType' => 'avl_unit_group', 'propName' => 'sys_name', 'propValueMask' => '*', 'sortType' => 'sys_name'],
            'force' => 1, 'flags' => 1, 'from' => 0, 'to' => 0,
        ])['items'] ?? [];
    }

    private function unitIdsFromGroups(array $groups, array $selectedGroupIds): array
    {
        $selectedGroupIds = array_map('intval', $selectedGroupIds);
        $unitIds = [];
        foreach ($groups as $group) {
            if (in_array((int) ($group['id'] ?? 0), $selectedGroupIds, true)) {
                $unitIds = array_merge($unitIds, array_map('intval', $group['u'] ?? []));
            }
        }
        return array_values(array_unique($unitIds));
    }

    private function searchUnits(string $sid): array
    {
        return $this->call($sid, 'core/search_items', [
            'spec' => ['itemsType' => 'avl_unit', 'propName' => 'sys_name', 'propValueMask' => '*', 'sortType' => 'sys_name'],
            'force' => 1,
            // Base + advanced device identity + last message/location + counters + connection.
            'flags' => 2106625,
            'from' => 0,
            'to' => 0,
        ])['items'] ?? [];
    }

    private function integration(string $companyId): object
    {
        $row = DB::table('wialon_integrations')->where('company_id', $companyId)->where('enabled', true)->first();
        if (!$row || !$row->token) throw new RuntimeException('GPS tracking is not configured for this company.');
        $row->token = decrypt($row->token);
        $row->resource_ids = json_decode($row->resource_ids ?: '[]', true) ?: [];
        $row->group_ids = json_decode($row->group_ids ?: '[]', true) ?: [];
        return (object) $row;
    }

    private function selectedUnitIds(string $companyId): array
    {
        $integration = $this->integration($companyId);
        if (!$integration->group_ids) return [];
        return $this->withSession($companyId, fn (string $sid) => $this->unitIdsFromGroups(
            $this->searchUnitGroups($sid),
            $integration->group_ids
        ));
    }

    private function assertSelectedUnit(string $companyId, int $unitId): void
    {
        if (!in_array($unitId, $this->selectedUnitIds($companyId), true)) {
            throw new RuntimeException('The GPS device is not enabled for this company.');
        }
    }

    private function withSession(string $companyId, callable $callback): mixed
    {
        $integration = $this->integration($companyId);
        $this->baseUrl = rtrim($integration->base_url ?: $this->baseUrl, '/');
        $login = $this->call(null, 'token/login', ['token' => $integration->token]);
        if (empty($login['eid'])) {
            throw new RuntimeException('The GPS service did not start a session.');
        }
        try {
            if (!empty($login['base_url'])) {
                $reported = rtrim((string) $login['base_url'], '/');
                $host = strtolower((string) parse_url($reported, PHP_URL_HOST));
                $allowedHost = false;
                foreach (['wialon.com', 'wialon.us', 'wialon.eu', 'wialon.org'] as $domain) {
                    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                        $allowedHost = true;
                        break;
                    }
                }
                if (parse_url($reported, PHP_URL_SCHEME) !== 'https' || !$allowedHost) {
                    throw new RuntimeException('The GPS service returned an invalid API host.');
                }
                $this->baseUrl = $reported;
                if ($integration->base_url !== $reported) {
                    DB::table('wialon_integrations')->where('company_id', $companyId)->update(['base_url' => $reported, 'updated_at' => now()]);
                }
            }
            return $callback($login['eid']);
        } finally {
            try {
                $this->call($login['eid'], 'core/logout', []);
            } catch (RuntimeException) {
                // The Wialon session expires even if logout is unavailable.
            }
        }
    }

    private function call(?string $sid, string $service, array $params): array
    {
        $body = ['svc' => $service, 'params' => json_encode($params, JSON_THROW_ON_ERROR)];
        if ($sid !== null) $body['sid'] = $sid;
        try {
            $response = Http::asForm()->timeout(20)->post($this->baseUrl . '/wialon/ajax.html', $body);
        } catch (ConnectionException $error) {
            throw new RuntimeException('The GPS service request failed.', 0, $error);
        }
        if (!$response->successful()) throw new RuntimeException('The GPS service request failed.');

        $data = $response->json();
        if (!is_array($data) || isset($data['error'])) {
            throw new RuntimeException('The GPS service rejected the request' . (isset($data['error']) ? ' (' . $data['error'] . ')' : '') . '.');
        }
        return $data;
    }
}
