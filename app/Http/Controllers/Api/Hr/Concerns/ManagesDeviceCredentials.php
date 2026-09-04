<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\AttendanceProviderManager;
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
        $fingerprint = $this->credentialFingerprint($data['card_number']);
        if ($existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $adapter = $providers->adapterFor($device);
        $owner = $adapter->cardOwner($device, $data['card_number']);
        abort_if($owner !== null, 409, $owner === $employeeNumber ? 'This card is already assigned to the Staff member.' : 'This card is already assigned to another terminal identity.');
        $result = $adapter->setCard($device, $employeeNumber, $data['card_number'], $data['card_type']);
        $event = $this->credentialEvent($request, $device, $mapping, 'card', 'set', $fingerprint, $this->maskCard($data['card_number']), $data['reason'], $data['idempotency_key'], $result);

        return response()->json(['status' => 'success', 'data' => $event], 201);
    }

    public function deleteCard(Request $request, string $deviceId, string $employeeNumber, string $fingerprint, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'card_management', false), 409, 'Card management is not verified for this device. Test the device first.');
        if ($existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $card = collect($providers->adapterFor($device)->cards($device, $employeeNumber))->first(fn ($row) => hash_equals($fingerprint, $this->credentialFingerprint($row['card_number'])));
        abort_unless($card, 404, 'The card is no longer assigned on the terminal.');
        $result = $providers->adapterFor($device)->deleteCard($device, $card['card_number']);
        $event = $this->credentialEvent($request, $device, $mapping, 'card', 'revoke', $fingerprint, $this->maskCard($card['card_number']), $data['reason'], $data['idempotency_key'], $result);

        return response()->json(['status' => 'success', 'data' => $event]);
    }

    public function setPin(Request $request, string $deviceId, string $employeeNumber, AttendanceProviderManager $providers): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160']]);
        [$device,$mapping] = $this->mappedIdentity($request, $deviceId, $employeeNumber);
        abort_unless((bool) data_get($device->capabilities, 'pin_management', false), 409, 'PIN management is not verified for this device. Test the device first.');
        if ($existing = DB::table('hr_attendance_credential_events')->where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json(['status' => 'success', 'data' => $existing]);
        }
        $result = $providers->adapterFor($device)->setPin($device, $employeeNumber, $data['pin']);
        $event = $this->credentialEvent($request, $device, $mapping, 'pin', 'set', null, null, $data['reason'], $data['idempotency_key'], $result);

        return response()->json(['status' => 'success', 'data' => $event]);
    }

    public function disposition(Request $request, string $deviceId, string $employeeNumber): JsonResponse
    {
        $data = $request->validate(['disposition' => ['required', Rule::in(['former_staff', 'vendor', 'test_identity', 'unknown', 'ignored'])], 'reason' => ['required', 'string', 'max:2000']]);
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        DB::table('hr_attendance_identity_dispositions')->updateOrInsert(['device_id' => $device->id, 'provider_person_id' => $employeeNumber], [
            'id' => (string) Str::uuid(), 'company_id' => $device->company_id, 'disposition' => $data['disposition'], 'reason' => $data['reason'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_identity_dispositions')->where('device_id', $device->id)->where('provider_person_id', $employeeNumber)->first()]);
    }

    private function mappedIdentity(Request $request, string $deviceId, string $employeeNumber): array
    {
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);
        $this->authorizedCompanyId($request, $device->company_id);
        abort_unless($device->status === 'active' && $device->integration_mode === 'direct_isapi', 409, 'Credential writes require an active direct-ISAPI device.');
        $mapping = DB::table('hr_attendance_person_mappings')->where('company_id', $device->company_id)->where('provider_person_id', $employeeNumber)->where('enrollment_status', 'verified')->where(fn ($q) => $q->where('device_id', $device->id)->orWhereNull('device_id'))->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', now()))->first();
        abort_unless($mapping, 422, 'A current verified Staff mapping is required.');

        return [$device, $mapping];
    }

    private function credentialFingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function maskCard(string $value): string
    {
        return str_repeat('•', max(0, strlen($value) - 4)).substr($value, -4);
    }

    private function credentialEvent(Request $request, AttendanceDevice $device, object $mapping, string $type, string $action, ?string $fingerprint, ?string $masked, string $reason, string $idempotency, array $result): object
    {
        $id = (string) Str::uuid();
        DB::table('hr_attendance_credential_events')->insert(['id' => $id, 'company_id' => $device->company_id, 'device_id' => $device->id, 'staff_id' => $mapping->staff_id, 'provider_person_id' => $mapping->provider_person_id, 'credential_type' => $type, 'action' => $action, 'credential_fingerprint' => $fingerprint, 'masked_reference' => $masked, 'status' => 'delivered', 'reason' => $reason, 'idempotency_key' => $idempotency, 'actor_user_id' => $request->user()->id, 'occurred_at' => now(), 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('hr_attendance_credential_events')->find($id);
    }
}
