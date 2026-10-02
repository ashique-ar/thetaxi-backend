<?php
namespace App\Services;

use App\Models\Website\WebsiteSetting;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class TenantDecisionService
{
    public function get(string $key, string $companyId, ?array $default = null): ?array
    {
        if (Schema::hasTable('tenant_decision_versions')) {
            $version = $this->activeVersion($key, $companyId);
            if (! $version) return $default;
            try {
                return $this->validate($key, json_decode((string) $version->value, true, 512, JSON_THROW_ON_ERROR));
            } catch (\JsonException|ValidationException) {
                return $default;
            }
        }
        $row = WebsiteSetting::query()->where('type', 'decision.'.$key)->where('company_id', $companyId)->first();
        if (! $row) return $default;
        $payload = json_decode((string) $row->value, true);
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'approved' || ! is_array($payload['value'] ?? null)) return $default;
        try {
            return $this->validate($key, $payload['value']);
        } catch (ValidationException) {
            return $default;
        }
    }

    public function activeVersion(string $key, string $companyId): ?object
    {
        if (! Schema::hasTable('tenant_decision_versions')) return null;
        $today = now()->toDateString();
        return DB::table('tenant_decision_versions')->where('company_id', $companyId)->where('decision_key', $key)->where('status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->orderByRaw('CASE WHEN effective_from IS NULL THEN 1 ELSE 0 END')->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    public function isApproved(string $key, string $companyId): bool
    {
        return $this->get($key, $companyId) !== null;
    }

    public function validate(string $key, array $value): array
    {
        $definition = collect(config('tenant_decisions', []))->firstWhere('key', $key);
        if (! $definition) throw ValidationException::withMessages(['key' => ['Unknown tenant decision.']]);
        $allowed = collect($definition['field_schema'])->pluck('key')->all();
        if (array_diff(array_keys($value), $allowed)) throw ValidationException::withMessages(['value' => ['The decision contains unsupported fields.']]);
        foreach ($definition['field_schema'] as $field) {
            $fieldKey = $field['key'];
            $input = $value[$fieldKey] ?? null;
            if (($field['required'] ?? false) && ($input === null || $input === '')) throw ValidationException::withMessages(["value.$fieldKey" => ['This approved fact is required.']]);
            if ($input === null || $input === '') continue;
            $valid = match ($field['type']) {
                'number' => is_numeric($input) && (! isset($field['min']) || (float) $input >= $field['min']),
                'date' => is_string($input) && $this->validDate($input),
                'timezone' => is_string($input) && in_array($input, timezone_identifiers_list(), true),
                'select' => is_string($input) && in_array($input, $field['options'] ?? [], true),
                default => is_string($input) && trim($input) !== '' && (! isset($field['pattern']) || preg_match($field['pattern'], $input) === 1),
            };
            if (! $valid) throw ValidationException::withMessages(["value.$fieldKey" => ['This value does not match the approved decision schema.']]);
        }
        return array_intersect_key($value, array_flip($allowed));
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
