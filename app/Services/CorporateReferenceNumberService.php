<?php

namespace App\Services;

use App\Models\Corporate\Corporate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorporateReferenceNumberService
{
    public function next(string $corporateId, string $type): string
    {
        return DB::transaction(function () use ($corporateId, $type): string {
            $corporate = Corporate::whereKey($corporateId)->lockForUpdate()->firstOrFail();
            $isItem = $type === 'item';
            $prefix = (string) ($isItem ? $corporate->booking_item_number_prefix : $corporate->booking_number_prefix);
            $number = max(1, (int) ($isItem ? $corporate->booking_item_number_start : $corporate->booking_number_start));
            $increment = max(1, (int) ($isItem ? $corporate->booking_item_number_increment : $corporate->booking_number_increment));
            $digits = min(12, max(1, (int) ($isItem ? $corporate->booking_item_number_digits : $corporate->booking_number_digits)));

            $sequence = DB::table('corporate_reference_sequences')
                ->where('corporate_id', $corporateId)
                ->where('reference_type', $type)
                ->lockForUpdate()
                ->first();
            $number = max($number, (int) ($sequence->next_number ?? $number));

            while (true) {
                $candidate = $prefix . str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
                if ($this->reserveIfAvailable($candidate, $type, $corporateId)) {
                    DB::table('corporate_reference_sequences')->updateOrInsert(
                        ['corporate_id' => $corporateId, 'reference_type' => $type],
                        [
                            'next_number' => $number + $increment,
                            'created_at' => $sequence->created_at ?? now(),
                            'updated_at' => now(),
                        ]
                    );

                    $this->rememberGenerated($candidate, $type, $corporateId);
                    return $candidate;
                }
                $number += $increment;
            }
        });
    }

    public function nextGlobal(string $prefix, string $type = 'booking'): string
    {
        return DB::transaction(function () use ($prefix, $type): string {
            $existingCodes = DB::table('bookings')->where('booking_number', 'like', $prefix . '%')->pluck('booking_number')
                ->merge(DB::table('booking_items')->where('item_code', 'like', $prefix . '%')->pluck('item_code'))
                ->merge(DB::table('reference_number_registry')->where('reference_code', 'like', $prefix . '%')->pluck('reference_code'));
            $number = 1;
            $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
            foreach ($existingCodes as $existingCode) {
                if (preg_match($pattern, (string) $existingCode, $matches)) {
                    $number = max($number, (int) $matches[1] + 1);
                }
            }

            while (true) {
                $candidate = $prefix . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
                if ($this->reserveIfAvailable($candidate, $type, null)) {
                    $this->rememberGenerated($candidate, $type, null);
                    return $candidate;
                }
                $number++;
            }
        });
    }

    /** Reserve a reference supplied by another trusted creation flow or reject duplicates. */
    public function reserveProvided(
        string $code,
        string $type,
        ?string $corporateId = null,
        string $field = 'reference_number',
    ): void
    {
        if ($this->existsInBookingsOrItems($code)) {
            $this->duplicateReference($field);
        }

        $reservation = DB::table('reference_number_registry')->where('reference_code', $code)->first();
        if ($reservation) {
            // A code reserved by a generator can be consumed once by the model
            // creation happening in this request. Admin-entered values cannot
            // claim another creation's reservation.
            $generated = request()->attributes->get($this->generatedAttribute($code));
            if ($generated
                && $generated['type'] === $type
                && (string) ($generated['corporate_id'] ?? '') === (string) ($corporateId ?? '')
                && $reservation->reference_type === $type
                && (string) ($reservation->corporate_id ?? '') === (string) ($corporateId ?? '')) {
                request()->attributes->remove($this->generatedAttribute($code));
                return;
            }
            $this->duplicateReference($field);
        }

        if (! $this->reserveIfAvailable($code, $type, $corporateId)) {
            $this->duplicateReference($field);
        }
    }

    private function reserveIfAvailable(string $code, string $type, ?string $corporateId): bool
    {
        if ($this->existsInBookingsOrItems($code)) {
            return false;
        }

        return DB::table('reference_number_registry')->insertOrIgnore([
            'reference_code' => $code,
            'reference_type' => $type,
            'corporate_id' => $corporateId,
            'created_at' => now(),
        ]) > 0;
    }

    private function existsInBookingsOrItems(string $code): bool
    {
        return DB::table('bookings')->where('booking_number', $code)->exists()
            || DB::table('booking_items')->where('item_code', $code)->exists();
    }

    private function rememberGenerated(string $code, string $type, ?string $corporateId): void
    {
        request()->attributes->set($this->generatedAttribute($code), [
            'type' => $type,
            'corporate_id' => $corporateId,
        ]);
    }

    private function generatedAttribute(string $code): string
    {
        return 'reserved_reference_' . hash('sha256', $code);
    }

    private function duplicateReference(string $field): never
    {
        throw ValidationException::withMessages([
            $field => ['This reference number is already in use. Choose a different number.'],
        ]);
    }
}
