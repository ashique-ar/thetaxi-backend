<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Hr\Support\CustomFieldValueValidation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §5.1: "configurable custom fields without schema changes for every
 * customer-specific attribute" — the Staff value surface already exists
 * (StaffCustomFieldValueService); this applies the same governed contract to
 * the remaining subjects definitions already declare (`applies_to`):
 * organization unit, position, and employment spell. Reuses the identical
 * hr_custom_field_values/_events/_access_events owner_type/owner_id ledger
 * with no schema change.
 */
class SubjectCustomFieldValueService
{
    use CustomFieldValueValidation;

    private const SUBJECTS = [
        'organization_unit' => ['table' => 'hr_organization_units', 'from' => 'effective_from', 'until' => 'effective_until'],
        'position' => ['table' => 'hr_positions', 'from' => 'effective_from', 'until' => 'effective_until'],
        'employment_spell' => ['table' => 'hr_employment_spells', 'from' => 'joined_at', 'until' => 'terminated_at'],
    ];

    public function listFor(string $ownerType, string $ownerId, string $companyId, User $actor): array
    {
        $subject = $this->subject($ownerType);
        $owner = $this->loadOwner($subject, $ownerId, $companyId);
        $definitions = DB::table('hr_custom_field_definitions')->where('company_id', $companyId)->where('applies_to', $ownerType)->where('active', true)
            ->orderBy('label')->orderBy('id')->get();
        $values = DB::table('hr_custom_field_values')->where('owner_type', $ownerType)->where('owner_id', $owner->id)->get()->keyBy('definition_id');
        $requestId = (string) Str::uuid();
        $result = [];
        foreach ($definitions as $definition) {
            if (! $this->canView($definition->confidentiality, $actor)) continue;
            $row = $values->get($definition->id);
            $legacy = $row && ($row->value_checksum === null || $row->effective_from === null);
            $value = null;
            if ($row && ! $legacy) {
                $value = $this->decrypt($row->encrypted_value);
                abort_unless(hash_equals($row->value_checksum, $this->valueChecksum($value)), 409, 'A custom-field value failed its integrity check.');
                DB::table('hr_custom_field_value_access_events')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $companyId, 'definition_id' => $definition->id,
                    'owner_type' => $ownerType, 'owner_id' => $owner->id, 'access_type' => 'viewed', 'actor_user_id' => $actor->id,
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

    public function put(string $ownerType, string $ownerId, string $definitionId, array $data, string $companyId, User $actor): array
    {
        return DB::transaction(function () use ($ownerType, $ownerId, $definitionId, $data, $companyId, $actor) {
            $subject = $this->subject($ownerType);
            abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->first(), 404, 'Legal entity was not found.');
            $owner = DB::table($subject['table'])->where('id', $ownerId)->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($owner, 404, ucfirst(str_replace('_', ' ', $ownerType)).' was not found in your legal entity.');
            $definition = DB::table('hr_custom_field_definitions')->where('id', $definitionId)->where('company_id', $companyId)->where('applies_to', $ownerType)->lockForUpdate()->first();
            abort_unless($definition, 404, 'Custom-field definition was not found in your legal entity.');
            abort_unless((bool) $definition->active, 409, 'Inactive custom-field definitions cannot accept values.');
            abort_unless((int) $definition->version === (int) $data['definition_version'], 409, 'Custom-field definition version is stale.');
            abort_unless($this->canView($definition->confidentiality, $actor), 403, 'This custom-field classification is not available to the actor.');
            $this->assertSubjectCovers($subject, $owner, $data['effective_from']);
            $value = $this->normalizeAndValidate($data['value'], $definition);
            $valueChecksum = $this->valueChecksum($value);
            $commandChecksum = $this->checksum([
                'owner_type' => $ownerType, 'owner_id' => $owner->id, 'definition_id' => $definitionId, 'definition_version' => (int) $data['definition_version'],
                'expected_version' => $data['expected_version'] ?? null, 'effective_from' => $data['effective_from'],
                'value_checksum' => $valueChecksum, 'reason' => $data['reason'],
            ]);
            if ($event = DB::table('hr_custom_field_value_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
                abort_unless($event->company_id === $companyId && $event->owner_type === $ownerType && $event->owner_id === $owner->id && $event->definition_id === $definitionId && hash_equals($event->command_checksum, $commandChecksum), 409, 'Custom-field value key was reused with different evidence.');
                return $this->eventResult($event, $definition);
            }
            $current = DB::table('hr_custom_field_values')->where('definition_id', $definitionId)->where('owner_type', $ownerType)->where('owner_id', $owner->id)->lockForUpdate()->first();
            if ($current) {
                abort_unless(isset($data['expected_version']) && (int) $current->version === (int) $data['expected_version'], 409, 'Custom-field value version is stale.');
                abort_if($current->effective_from !== null && $data['effective_from'] < $current->effective_from && ! $actor->can('hr.custom-fields.values.backdate'), 403, 'Backdating a custom-field value requires separate permission.');
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
            else DB::table('hr_custom_field_values')->insert($payload + ['id' => $id, 'definition_id' => $definitionId, 'owner_type' => $ownerType, 'owner_id' => $owner->id, 'created_at' => now()]);
            $eventId = (string) Str::uuid();
            DB::table('hr_custom_field_value_events')->insert([
                'id' => $eventId, 'company_id' => $companyId, 'custom_field_value_id' => $id, 'definition_id' => $definitionId,
                'definition_version' => (int) $definition->version, 'owner_type' => $ownerType, 'owner_id' => $owner->id,
                'value_version' => $version, 'event_type' => $current ? 'updated' : 'created',
                'before_encrypted_value' => $current?->encrypted_value, 'before_checksum' => $current?->value_checksum,
                'after_encrypted_value' => $encrypted, 'after_checksum' => $valueChecksum, 'effective_from' => $data['effective_from'],
                'reason' => $data['reason'], 'actor_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key'],
                'command_checksum' => $commandChecksum, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $this->eventResult(DB::table('hr_custom_field_value_events')->where('id', $eventId)->first(), $definition);
        });
    }

    private function subject(string $ownerType): array
    {
        abort_unless(isset(self::SUBJECTS[$ownerType]), 422, 'Unsupported custom-field subject type.');
        return self::SUBJECTS[$ownerType];
    }

    private function loadOwner(array $subject, string $ownerId, string $companyId): object
    {
        $owner = DB::table($subject['table'])->where('id', $ownerId)->where('company_id', $companyId)->first();
        abort_unless($owner, 404, 'Subject was not found in your legal entity.');
        return $owner;
    }

    private function assertSubjectCovers(array $subject, object $owner, string $date): void
    {
        $from = $owner->{$subject['from']};
        $until = $owner->{$subject['until']};
        abort_unless($from !== null && $from <= $date && ($until === null || $until >= $date), 422,
            'A custom-field effective date must be covered by the subject\'s own retained effective period.');
    }

    private function eventResult(object $event, object $definition): array
    {
        $value = $this->decrypt($event->after_encrypted_value);
        abort_unless(hash_equals($event->after_checksum, $this->valueChecksum($value)), 409, 'A custom-field event failed its integrity check.');
        return ['definition_id' => $definition->id, 'definition_version' => (int) $definition->version, 'field_key' => $definition->field_key, 'label' => $definition->label, 'data_type' => $definition->data_type, 'validation_rules' => $this->decodeRules($definition->validation_rules), 'confidentiality' => $definition->confidentiality, 'required' => (bool) $definition->required, 'value_id' => $event->custom_field_value_id, 'value_version' => (int) $event->value_version, 'value_definition_version' => (int) $event->definition_version, 'effective_from' => $event->effective_from, 'value' => $value, 'legacy_unverified' => false, 'definition_stale' => (int) $event->definition_version !== (int) $definition->version];
    }
}
