<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Card/PIN credential lifecycle (issue, view history, revoke) and identity
 * disposition tagging for a mapped Staff-to-terminal identity on a
 * Hikvision-family attendance terminal.
 *
 * @see \App\Http\Controllers\Api\Hr\AttendanceDeviceController
 */
trait ManagesDeviceCredentials
{
    public function credentials(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        $cards = collect($providers->adapterFor($device)->cards($device, $employeeNumber))->map(fn ($card) => [
            'fingerprint' => $this->credentialFingerprint($card['card_number']),
            'masked_reference' => $this->maskCard($card['card_number']),
            'card_type' => $card['card_type'],
        ])->values();
        $history = DB::table('hr_attendance_credential_events')->where('device_id', $device->id)->where('provider_person_id', $employeeNumber)->latest('occurred_at')->limit(50)->get(['id', 'credential_type', 'action', 'masked_reference', 'status', 'reason', 'actor_user_id', 'occurred_at']);

        return response()->json(['status' => 'success', 'data' => ['mapping' => $mapping, 'cards' => $cards, 'history' => $history, 'pin' => ['configured' => $history->contains(fn ($row) => $row->credential_type === 'pin' && $row->action === 'set' && $row->status === 'delivered')]]]);
    }

    public function setCard(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['card_number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'], 'card_type' => ['required', Rule::in(['normalCard', 'patrolCard', 'hijackCard', 'superCard'])], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'card_management', false), 409, 'Card management is not verified for this device. Test the device first.');
        $this->requireActiveCredentialOwner($device, $mapping);
        $fingerprint = $this->credentialFingerprint($data['card_number']);
        $checksum = $this->credentialRequestChecksum($device, $mapping, $request, 'card', 'set', $data);
        if ($existing = $this->existingCredentialEvent($data['idempotency_key'], $checksum, $device, $employeeNumber, 'card', 'set', $request->user()->id)) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $adapter = $providers->adapterFor($device);
        $owner = $adapter->cardOwner($device, $data['card_number']);
        if ($owner !== null) {
            if ($existing = $this->existingCredentialEvent($data['idempotency_key'], $checksum, $device, $employeeNumber, 'card', 'set', $request->user()->id)) {
                return response()->json(['status' => 'success', 'data' => $existing]);
            }
            abort(409, $owner === $employeeNumber ? 'This card is already assigned to the Staff member.' : 'This card is already assigned to another terminal identity.');
        }
        [$event, $reserved] = $this->reserveCredentialEvent($request, $device, $mapping, 'card', 'set', $fingerprint, $this->maskCard($data['card_number']), $data['reason'], $data['idempotency_key'], $checksum, $employeeNumber);
        if (! $reserved) return response()->json(['status' => 'success', 'data' => $event]);
        $result = $adapter->setCard($device, $employeeNumber, $data['card_number'], $data['card_type']);
        $event = $this->completeCredentialEvent($event->id, $result);

        return response()->json(['status' => 'success', 'data' => $event], 201);
    }

    public function deleteCard(Request $request, string $deviceId, string $employeeNumber, string $fingerprint, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'card_management', false), 409, 'Card management is not verified for this device. Test the device first.');
        $checksum = $this->credentialRequestChecksum($device, $mapping, $request, 'card', 'revoke', $data + ['fingerprint' => $fingerprint]);
        if ($existing = $this->existingCredentialEvent($data['idempotency_key'], $checksum, $device, $employeeNumber, 'card', 'revoke', $request->user()->id)) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $card = collect($providers->adapterFor($device)->cards($device, $employeeNumber))->first(fn ($row) => hash_equals($fingerprint, $this->credentialFingerprint($row['card_number'])));
        if (! $card) {
            if ($existing = $this->existingCredentialEvent($data['idempotency_key'], $checksum, $device, $employeeNumber, 'card', 'revoke', $request->user()->id)) {
                return response()->json(['status' => 'success', 'data' => $existing]);
            }
            abort(404, 'The card is no longer assigned on the terminal.');
        }
        [$event, $reserved] = $this->reserveCredentialEvent($request, $device, $mapping, 'card', 'revoke', $fingerprint, $this->maskCard($card['card_number']), $data['reason'], $data['idempotency_key'], $checksum, $employeeNumber);
        if (! $reserved) return response()->json(['status' => 'success', 'data' => $event]);
        $result = $providers->adapterFor($device)->deleteCard($device, $card['card_number']);
        $event = $this->completeCredentialEvent($event->id, $result);

        return response()->json(['status' => 'success', 'data' => $event]);
    }

    public function setPin(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'pin_management', false), 409, 'PIN management is not verified for this device. Test the device first.');
        $this->requireActiveCredentialOwner($device, $mapping);
        $checksum = $this->credentialRequestChecksum($device, $mapping, $request, 'pin', 'set', $data);
        if ($existing = $this->existingCredentialEvent($data['idempotency_key'], $checksum, $device, $employeeNumber, 'pin', 'set', $request->user()->id)) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        [$event, $reserved] = $this->reserveCredentialEvent($request, $device, $mapping, 'pin', 'set', null, null, $data['reason'], $data['idempotency_key'], $checksum, $employeeNumber);
        if (! $reserved) return response()->json(['status' => 'success', 'data' => $event]);
        $result = $providers->adapterFor($device)->setPin($device, $employeeNumber, $data['pin']);
        $event = $this->completeCredentialEvent($event->id, $result);

        return response()->json(['status' => 'success', 'data' => $event]);
    }

    public function disposition(Request $request, string $deviceId, string $employeeNumber): JsonResponse
    {
        $data = $request->validate(['disposition' => ['required', Rule::in(['former_staff', 'vendor', 'test_identity', 'unknown', 'ignored'])], 'reason' => ['required', 'string', 'max:2000']]);
        $device = $this->authorizedDevice($request, $deviceId);
        DB::table('hr_attendance_identity_dispositions')->updateOrInsert(['device_id' => $device->id, 'provider_person_id' => $employeeNumber], [
            'id' => (string) Str::uuid(), 'company_id' => $device->company_id, 'disposition' => $data['disposition'], 'reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_identity_dispositions')->where('device_id', $device->id)->where('provider_person_id', $employeeNumber)->first()]);
    }

    private function mappedIdentity(Request $request, string $deviceId, string $employeeNumber): array
    {
        $device = $this->authorizedDevice($request, $deviceId);
        abort_unless($device->status === 'active' && $device->integration_mode === 'direct_isapi', 409, 'Credential writes require an active direct-ISAPI device.');
        $mapping = DB::table('hr_attendance_person_mappings')->where('company_id', $device->company_id)->where('provider_person_id', $employeeNumber)->where('enrollment_status', 'verified')->where(fn ($q) => $q->where('device_id', $device->id)->orWhereNull('device_id'))->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', now()))->first();
        abort_unless($mapping, 422, 'A current verified Staff mapping is required.');
        abort_unless(Staff::query()->whereKey($mapping->staff_id)->where('company_id', $device->company_id)->exists(), 404);

        return [$device, $mapping];
    }

    private function requireActiveCredentialOwner(AttendanceDevice $device, object $mapping): void
    {
        $staff = Staff::query()->whereKey($mapping->staff_id)->where('company_id', $device->company_id)->firstOrFail();
        abort_if(filled($staff->employment_ended_at), 409, 'Former Staff cannot receive attendance credentials.');
    }

    private function credentialFingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function credentialRequestChecksum(AttendanceDevice $device, object $mapping, Request $request, string $type, string $action, array $data): string
    {
        return hash_hmac('sha256', json_encode([
            'company_id' => $device->company_id, 'device_id' => $device->id, 'staff_id' => $mapping->staff_id,
            'provider_person_id' => $mapping->provider_person_id, 'credential_type' => $type, 'action' => $action,
            'actor_user_id' => $request->user()->id, 'request' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function existingCredentialEvent(string $key, string $checksum, AttendanceDevice $device, string $employeeNumber, string $type, string $action, string $actorId): ?object
    {
        $existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $key)->first();
        abort_unless(! $existing || (
            $existing->company_id === $device->company_id && $existing->device_id === $device->id
            && $existing->provider_person_id === $employeeNumber && $existing->credential_type === $type
            && $existing->action === $action && $existing->actor_user_id === $actorId
            && hash_equals((string) $existing->request_checksum, $checksum)
        ), 409, 'Credential idempotency key was reused with different evidence.');
        abort_if($existing && $existing->status !== 'delivered', 409, 'A prior terminal write is unresolved. Verify terminal state before retrying.');

        return $existing;
    }

    private function maskCard(string $value): string
    {
        return str_repeat('•', max(0, strlen($value) - 4)).substr($value, -4);
    }

    private function reserveCredentialEvent(Request $request, AttendanceDevice $device, object $mapping, string $type, string $action, ?string $fingerprint, ?string $masked, string $reason, string $idempotency, string $checksum, string $employeeNumber): array
    {
        $id = (string) Str::uuid();
        try {
            DB::table('hr_attendance_credential_events')->insert(['id' => $id, 'company_id' => $device->company_id, 'device_id' => $device->id, 'staff_id' => $mapping->staff_id, 'provider_person_id' => $mapping->provider_person_id, 'credential_type' => $type, 'action' => $action, 'credential_fingerprint' => $fingerprint, 'masked_reference' => $masked, 'status' => 'pending', 'reason' => $reason, 'idempotency_key' => $idempotency, 'request_checksum' => $checksum, 'actor_user_id' => $request->user()->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) throw $exception;
            $existing = $this->existingCredentialEvent($idempotency, $checksum, $device, $employeeNumber, $type, $action, $request->user()->id);
            if ($existing) return [$existing, false];
            throw $exception;
        }

        return [DB::table('hr_attendance_credential_events')->find($id), true];
    }

    private function completeCredentialEvent(string $id, array $result): object
    {
        DB::table('hr_attendance_credential_events')->where('id', $id)->where('status', 'pending')->update([
            'status' => 'delivered', 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);

        return DB::table('hr_attendance_credential_events')->find($id);
    }
}
