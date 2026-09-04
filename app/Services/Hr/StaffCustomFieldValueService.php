<?php

namespace App\Services\Hr;

use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\Support\CustomFieldValueValidation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffCustomFieldValueService
{
    use CustomFieldValueValidation;

    public function listForStaff(Staff $staff, User $actor): array
    {
        $definitions = DB::table('hr_custom_field_definitions')->where('company_id', $staff->company_id)->where('applies_to', 'staff')->where('active', true)
            ->orderBy('label')->orderBy('id')->get();
        $values = DB::table('hr_custom_field_values')->where('owner_type', 'staff')->where('owner_id', $staff->id)->get()->keyBy('definition_id');
        $requestId = (string) Str::uuid();
        $result = [];
        foreach ($definitions as $definition) {
            if (! $this->canView($definition->confidentiality, $actor)) continue;
            $row = $values->get($definition->id);
            $legacy = $row && ($row->value_checksum === null || $row->effective_from === null);
            $value = null;
            if ($row && ! $legacy) {
                $value = $this->decrypt($row->encrypted_value);
                abort_unless(hash_equals($row->value_checksum, $this->valueChecksum($value)), 409, 'A Staff custom-field value failed its integrity check.');
                DB::table('hr_custom_field_value_access_events')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $staff->company_id, 'definition_id' => $definition->id,
                    'owner_type' => 'staff', 'owner_id' => $staff->id, 'access_type' => 'viewed', 'actor_user_id' => $actor->id,
                    'request_id' => $requestId, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $result[] = [
                'definition_id' => $definition->id, 'definition_version' => (int) $definition->version,
                'field_key' => $definition->field_key, 'label' => $definition->label, 'data_type' => $definition->data_type,
                'validation_rules' => $this->decodeRules($definition->validation_rules), 'confidentiality' => $definition->confidentiality,
                'required' => (bool) $definition->required, 'value_id' => $row?->id, 'value_version' => $row ? (int) $row->version : null,
                'value_definition_version' => $row ? (int) $row->definition_version : null,
                'effective_from' => $row?->effective_from, 'value' => $legacy ? null : $value,
                'legacy_unverified' => (bool) $legacy, 'definition_stale' => (bool) ($row && (int) $row->definition_version !== (int) $definition->version),
            ];
        }
        return $result;
    }

    public function put(Staff $staff, string $definitionId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($staff, $definitionId, $data, $actor) {
            abort_unless(DB::table('companies')->where('id', $staff->company_id)->lockForUpdate()->first(), 404, 'Staff legal entity was not found.');
            $staff = Staff::withTrashed()->whereKey($staff->id)->where('company_id', $staff->company_id)->lockForUpdate()->firstOrFail();
            $definition = DB::table('hr_custom_field_definitions')->where('id', $definitionId)->where('company_id', $staff->company_id)->where('applies_to', 'staff')->lockForUpdate()->first();
            abort_unless($definition, 404, 'Staff custom-field definition was not found in your legal entity.');
            abort_unless((bool) $definition->active, 409, 'Inactive custom-field definitions cannot accept values.');
            abort_unless((int) $definition->version === (int) $data['definition_version'], 409, 'Custom-field definition version is stale.');
            abort_unless($this->canView($definition->confidentiality, $actor), 403, 'This custom-field classification is not available to the actor.');
            $this->assertEmploymentCovers($staff, $data['effective_from']);
            $value = $this->normalizeAndValidate($data['value'], $definition);
            $valueChecksum = $this->valueChecksum($value);
            $commandChecksum = $this->checksum([
                'staff_id' => $staff->id, 'definition_id' => $definitionId, 'definition_version' => (int) $data['definition_version'],
                'expected_version' => $data['expected_version'] ?? null, 'effective_from' => $data['effective_from'],
                'value_checksum' => $valueChecksum, 'reason' => $data['reason'],
            ]);
            if ($event = DB::table('hr_custom_field_value_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
                abort_unless($event->company_id === $staff->company_id && $event->owner_id === $staff->id && $event->definition_id === $definitionId && hash_equals($event->command_checksum, $commandChecksum), 409, 'Custom-field value key was reused with different evidence.');
                return $this->eventResult($event, $definition);
            }
            $current = DB::table('hr_custom_field_values')->where('definition_id', $definitionId)->where('owner_type', 'staff')->where('owner_id', $staff->id)->lockForUpdate()->first();
            if ($current) {
                abort_unless(isset($data['expected_version']) && (int) $current->version === (int) $data['expected_version'], 409, 'Custom-field value version is stale.');
                abort_if($current->effective_from !== null && $data['effective_from'] < $current->effective_from && ! $actor->can('hr.custom-fields.values.backdate'), 403, 'Backdating a Staff custom-field value requires separate permission.');
            } else {
                abort_if(isset($data['expected_version']) && (int) $data['expected_version'] !== 0, 409, 'A new custom-field value must use an empty or zero expected version.');
            }
            $encrypted = Crypt::encryptString($this->canonicalValue($value));
            $id = $current?->id ?? (string) Str::uuid();
            $version = $current ? (int) $current->version + 1 : 1;
            $payload = [
                'definition_version' => (int) $definition->version, 'encrypted_value' => $encrypted, 'value_checksum' => $valueChecksum,
                'effective_from' => $data['effective_from'], 'change_reason' => $data['reason'], 'version' => $version,
                'updated_by' => $actor->id, 'updated_at' => now(),
            ];
            if ($current) DB::table('hr_custom_field_values')->where('id', $id)->update($payload);
            else DB::table('hr_custom_field_values')->insert($payload + ['id' => $id, 'definition_id' => $definitionId, 'owner_type' => 'staff', 'owner_id' => $staff->id, 'created_at' => now()]);
            $eventId = (string) Str::uuid();
            DB::table('hr_custom_field_value_events')->insert([
                'id' => $eventId, 'company_id' => $staff->company_id, 'custom_field_value_id' => $id, 'definition_id' => $definitionId,
                'definition_version' => (int) $definition->version, 'owner_type' => 'staff', 'owner_id' => $staff->id,
                'value_version' => $version, 'event_type' => $current ? 'updated' : 'created',
                'before_encrypted_value' => $current?->encrypted_value, 'before_checksum' => $current?->value_checksum,
                'after_encrypted_value' => $encrypted, 'after_checksum' => $valueChecksum, 'effective_from' => $data['effective_from'],
                'reason' => $data['reason'], 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'],
                'command_checksum' => $commandChecksum, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->timeline($staff, $definition, $id, $version, $data['effective_from'], $data['idempotency_key']);
            return $this->eventResult(DB::table('hr_custom_field_value_events')->where('id', $eventId)->first(), $definition);
        });
    }

    private function assertEmploymentCovers(Staff $staff, string $date): void
    {
        abort_unless(DB::table('hr_employment_spells')->where('staff_id', $staff->id)->where('company_id', $staff->company_id)->where('joined_at', '<=', $date)
            ->where(fn ($range) => $range->whereNull('terminated_at')->orWhere('terminated_at', '>=', $date))->exists(), 422, 'A Staff custom-field effective date must be covered by retained employment.');
    }

    private function eventResult(object $event, object $definition): array
    {
        $value = $this->decrypt($event->after_encrypted_value);
        abort_unless(hash_equals($event->after_checksum, $this->valueChecksum($value)), 409, 'A Staff custom-field event failed its integrity check.');
        return ['definition_id' => $definition->id, 'definition_version' => (int) $definition->version, 'field_key' => $definition->field_key, 'label' => $definition->label, 'data_type' => $definition->data_type, 'validation_rules' => $this->decodeRules($definition->validation_rules), 'confidentiality' => $definition->confidentiality, 'required' => (bool) $definition->required, 'value_id' => $event->custom_field_value_id, 'value_version' => (int) $event->value_version, 'value_definition_version' => (int) $event->definition_version, 'effective_from' => $event->effective_from, 'value' => $value, 'legacy_unverified' => false, 'definition_stale' => (int) $event->definition_version !== (int) $definition->version];
    }

    private function timeline(Staff $staff, object $definition, string $valueId, int $version, string $effectiveFrom, string $key): void
    {
        $spellId = DB::table('hr_employment_spells')->where('staff_id', $staff->id)->where('joined_at', '<=', $effectiveFrom)->where(fn ($range) => $range->whereNull('terminated_at')->orWhere('terminated_at', '>=', $effectiveFrom))->latest('spell_number')->value('id');
        DB::table('hr_employee_timeline_events')->insertOrIgnore(['id' => (string) Str::uuid(), 'staff_id' => $staff->id, 'employment_spell_id' => $spellId, 'domain' => 'people', 'event_type' => 'custom_field_value_changed', 'source_type' => 'custom_field_value', 'source_id' => $valueId, 'title' => 'Custom employee attribute updated', 'safe_summary' => json_encode(['field_key' => $definition->field_key, 'value_version' => $version, 'classification' => $definition->confidentiality], JSON_THROW_ON_ERROR), 'confidentiality' => $definition->confidentiality, 'effective_at' => $effectiveFrom, 'recorded_at' => now(), 'idempotency_key' => 'custom-field-value:'.$key, 'created_at' => now(), 'updated_at' => now()]);
    }
}
