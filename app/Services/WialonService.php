<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WialonService
{
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

            $selected = array_map('intval', $integration->unit_ids);
            if ($integration->group_ids) {
                foreach ($this->searchUnitGroups($sid) as $group) {
                    if (in_array((int) ($group['id'] ?? 0), array_map('intval', $integration->group_ids), true)) {
                        $selected = array_merge($selected, array_map('intval', $group['u'] ?? []));
                    }
                }
            }
            $selected = array_unique($selected);
            return $this->portalUnits(array_values(array_filter($units, fn ($unit) => in_array((int) ($unit['id'] ?? 0), $selected, true))));
        });
    }

    private function portalUnits(array $units): array
    {
        return array_values(array_map(static function (array $unit): array {
            $safe = array_intersect_key($unit, array_flip(['id', 'nm', 'netconn']));
            $kilometers = data_get($unit, 'counters.cnm_km');
            if (is_numeric($kilometers)) {
                $safe['mileage_km'] = (int) round((float) $kilometers);
            } elseif (is_numeric(data_get($unit, 'counters.cnm'))) {
                $mileage = (float) data_get($unit, 'counters.cnm');
                $safe['mileage_km'] = (int) round(
                    in_array((int) ($unit['mu'] ?? 0), [1, 2], true)
                        ? $mileage * 1.609344
                        : $mileage
                );
            }
            if (is_numeric(data_get($unit, 'counters.cneh'))) {
                $safe['engine_hours'] = (float) data_get($unit, 'counters.cneh');
            }
            if (isset($unit['pos']) && is_array($unit['pos'])) {
                $safe['pos'] = array_intersect_key($unit['pos'], array_flip(['t', 'x', 'y', 's']));
            }
            return $safe;
        }, $units));
    }

    public function syncVehicles(string $companyId): int
    {
        $synced = 0;
        $integration = $this->integration($companyId);
        $catalog = $this->withSession($companyId, function (string $sid) use ($integration) {
            $groups = $integration->group_ids ? $this->searchUnitGroups($sid) : [];
            return ['units' => $this->searchUnits($sid), 'groups' => $groups];
        });
        $selectedUnitIds = array_map('intval', $integration->unit_ids);
        foreach ($catalog['groups'] as $group) {
            if (in_array((int) ($group['id'] ?? 0), array_map('intval', $integration->group_ids), true)) {
                $selectedUnitIds = array_merge($selectedUnitIds, array_map('intval', $group['u'] ?? []));
            }
        }
        $selectedUnitIds = array_unique($selectedUnitIds);
        $units = array_values(array_filter($catalog['units'], fn ($unit) => in_array((int) ($unit['id'] ?? 0), $selectedUnitIds, true)));
        foreach ($units as $unit) {
            if (empty($unit['id'])) continue;
            $vehicle = \App\Models\Vehicle\Vehicle::withInactive()->withTrashed()
                ->where('company_id', $companyId)
                ->where('wialon_unit_id', (int) $unit['id'])->first();
            if (!$vehicle && !empty($unit['nm'])) {
                $name = mb_strtolower(trim($unit['nm']));
                $vehicle = \App\Models\Vehicle\Vehicle::withInactive()
                    ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                    ->whereNull('wialon_unit_id')
                    ->where(fn ($query) => $query->whereRaw('LOWER(license_plate) = ?', [$name])
                        ->orWhereRaw('LOWER(registration_no) = ?', [$name]))
                    ->first();
            }
            if (!$vehicle || $vehicle->trashed()) continue;
            if ($this->isImportedPlaceholder($vehicle, $unit)) {
                $vehicle->delete();
                continue;
            }

            $mileage = data_get($unit, 'counters.cnm_km');
            if (!is_numeric($mileage)) {
                $mileage = data_get($unit, 'counters.cnm');
                if (is_numeric($mileage) && in_array((int) ($unit['mu'] ?? 0), [1, 2], true)) {
                    $mileage *= 1.609344;
                }
            }
            if ($vehicle->company_id && (string) $vehicle->company_id !== $companyId) continue;
            $vehicle->company_id ??= $companyId;
            $vehicle->wialon_unit_id = (int) $unit['id'];
            $vehicle->wialon_unique_id = $unit['uid'] ?? $vehicle->wialon_unique_id;
            $vehicle->wialon_hw_type_id = $unit['hw'] ?? $vehicle->wialon_hw_type_id;
            $vehicle->wialon_mileage = is_numeric($mileage) ? $mileage : $vehicle->wialon_mileage;
            $vehicle->wialon_last_message_at = data_get($unit, 'pos.t') ? now()->setTimestamp((int) $unit['pos']['t']) : $vehicle->wialon_last_message_at;
            $vehicle->wialon_last_synced_at = now();
            if (is_numeric($mileage)) {
                $vehicle->initial_mileage ??= (int) round($mileage);
                $vehicle->current_mileage = (int) round($mileage);
            }
            $vehicle->save();
            $synced++;
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
        $this->assertSelectedUnit($companyId, $unitId);
        $result = $this->withSession($companyId, fn (string $sid) => $this->call($sid, 'unit/update_mileage_counter', [
            'itemId' => $unitId, 'newValue' => $mileageKm,
        ]));
        return (int) ($result['cnm'] ?? $mileageKm);
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
            throw new RuntimeException('The selected Wialon resource is not enabled for this company.');
        }
        return $this->withSession($companyId, function (string $sid) use ($unitId, $resourceId, $templateId, $from, $to, $tableIndex, $offset) {
            $resources = $this->searchReportResources($sid);
            $resource = collect($resources)->firstWhere('id', $resourceId);
            if (!$resource || !collect($resource['templates'])->contains(fn ($template) => (int) $template['id'] === $templateId)) {
                throw new RuntimeException('The selected Wialon report template is unavailable.');
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
                    throw new \InvalidArgumentException('The selected Wialon report table is unavailable.');
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

    public function validateSelections(string $companyId, array $resourceIds, array $groupIds, array $unitIds): void
    {
        $available = $this->withSession($companyId, fn (string $sid) => [
            'resource_ids' => array_column($this->searchReportResources($sid), 'id'),
            'group_ids' => array_column($this->searchUnitGroups($sid), 'id'),
            'unit_ids' => array_column($this->searchUnits($sid), 'id'),
        ]);
        $errors = [];
        foreach (['resource_ids' => $resourceIds, 'group_ids' => $groupIds, 'unit_ids' => $unitIds] as $field => $selectedIds) {
            $allowedIds = array_map('intval', $available[$field]);
            if (array_diff(array_map('intval', $selectedIds), $allowedIds)) {
                $errors[$field] = ['One or more selected Wialon items are unavailable to this company token. Refresh the Wialon catalog and select available items.'];
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
        if (!$row || !$row->token) throw new RuntimeException('Wialon is not configured for this company.');
        $row->token = decrypt($row->token);
        $row->unit_ids = json_decode($row->unit_ids ?: '[]', true) ?: [];
        $row->resource_ids = json_decode($row->resource_ids ?: '[]', true) ?: [];
        $row->group_ids = json_decode($row->group_ids ?: '[]', true) ?: [];
        return (object) $row;
    }

    private function selectedUnitIds(string $companyId): array
    {
        $integration = $this->integration($companyId);
        $ids = array_map('intval', $integration->unit_ids);
        if ($integration->group_ids) {
            foreach ($this->unitGroups($companyId) as $group) {
                if (in_array((int) ($group['id'] ?? 0), array_map('intval', $integration->group_ids), true)) {
                    $ids = array_merge($ids, array_map('intval', $group['u'] ?? []));
                }
            }
        }
        $ids = array_merge($ids, \App\Models\Vehicle\Vehicle::withInactive()
            ->where('company_id', $companyId)
            ->whereNotNull('wialon_unit_id')
            ->pluck('wialon_unit_id')->map(fn ($id) => (int) $id)->all());
        return array_values(array_unique($ids));
    }

    private function assertSelectedUnit(string $companyId, int $unitId): void
    {
        if (!in_array($unitId, $this->selectedUnitIds($companyId), true)) {
            throw new RuntimeException('The Wialon unit is not enabled for this company.');
        }
    }

    private function withSession(string $companyId, callable $callback): mixed
    {
        $integration = $this->integration($companyId);
        $this->baseUrl = rtrim($integration->base_url ?: $this->baseUrl, '/');
        $login = $this->call(null, 'token/login', ['token' => $integration->token]);
        if (empty($login['eid'])) {
            throw new RuntimeException('Wialon did not return a session.');
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
                    throw new RuntimeException('Wialon returned an invalid API host.');
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
            throw new RuntimeException('Wialon request failed.', 0, $error);
        }
        if (!$response->successful()) throw new RuntimeException('Wialon request failed.');

        $data = $response->json();
        if (!is_array($data) || isset($data['error'])) {
            throw new RuntimeException('Wialon rejected the request' . (isset($data['error']) ? ' (' . $data['error'] . ')' : '') . '.');
        }
        return $data;
    }
}
