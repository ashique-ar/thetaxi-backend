<?php

namespace App\Services;

use App\Models\Booking\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CorporateBookingSubmissionService
{
    public function create(string $corporateId, string $actorUserId, array $payload, callable $create): array
    {
        $key = (string) $payload['idempotency_key'];
        unset($payload['idempotency_key']);
        $requestHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $existing = fn () => DB::table('corporate_booking_submission_idempotencies')
            ->where('corporate_id', $corporateId)->where('actor_user_id', $actorUserId)
            ->where('idempotency_key', $key)->first();
        $replay = function (object $record) use ($requestHash, $corporateId): array {
            abort_unless(hash_equals((string) $record->request_hash, $requestHash), 409, 'This booking submission key was already used for different trip details.');
            return [
                'booking' => Booking::query()->where('corporate_account_id', $corporateId)->findOrFail($record->booking_id),
                'replayed' => true,
            ];
        };

        try {
            return DB::transaction(function () use ($existing, $replay, $create, $payload, $corporateId, $actorUserId, $key, $requestHash): array {
                $record = $existing();
                if ($record) return $replay($record);

                $booking = $create($payload);
                DB::table('corporate_booking_submission_idempotencies')->insert([
                    'id' => (string) Str::uuid(),
                    'corporate_id' => $corporateId,
                    'actor_user_id' => $actorUserId,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'booking_id' => $booking->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['booking' => $booking, 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $record = $existing();
            if (! $record) throw $exception;
            return $replay($record);
        }
    }
}
