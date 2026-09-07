<?php
namespace App\Services;

use App\Models\Website\WebsiteSetting;

final class TenantDecisionService
{
    public function get(string $key, string $companyId, ?array $default = null): ?array
    {
        $row = WebsiteSetting::query()->where('type', 'decision.'.$key)->where('company_id', $companyId)->first();
        if (! $row) return $default;
        $payload = json_decode((string) $row->value, true);
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'approved' || ! is_array($payload['value'] ?? null)) return $default;
        return $payload['value'];
    }

    public function isApproved(string $key, string $companyId): bool
    {
        return $this->get($key, $companyId) !== null;
    }
}
