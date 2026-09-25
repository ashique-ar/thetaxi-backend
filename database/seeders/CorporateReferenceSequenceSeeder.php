<?php

namespace Database\Seeders;

use App\Models\Corporate\Corporate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Initialize each company's new reference settings from its existing data.
 * Existing booking numbers and trip references are deliberately left untouched.
 */
class CorporateReferenceSequenceSeeder extends Seeder
{
    public function run(): void
    {
        Corporate::query()
            ->whereNull('reference_numbers_seeded_at')
            ->orderBy('id')
            ->chunkById(100, function ($corporates): void {
                foreach ($corporates as $corporate) {
                    DB::transaction(function () use ($corporate): void {
                        $lockedCorporate = Corporate::whereKey($corporate->id)->lockForUpdate()->first();
                        if (! $lockedCorporate || $lockedCorporate->reference_numbers_seeded_at) {
                            return;
                        }

                        $this->initializeSequence($lockedCorporate, 'booking');
                        $this->initializeSequence($lockedCorporate, 'item');
                        DB::table('corporates')->where('id', $lockedCorporate->id)->update([
                            'reference_numbers_seeded_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
                }
            });
    }

    private function initializeSequence(Corporate $corporate, string $type): void
    {
        $isItem = $type === 'item';
        $prefixField = $isItem ? 'booking_item_number_prefix' : 'booking_number_prefix';
        $startField = $isItem ? 'booking_item_number_start' : 'booking_number_start';
        $incrementField = $isItem ? 'booking_item_number_increment' : 'booking_number_increment';
        $digitsField = $isItem ? 'booking_item_number_digits' : 'booking_number_digits';

        $prefix = (string) $corporate->{$prefixField};
        $start = max(1, (int) $corporate->{$startField});
        $increment = max(1, (int) $corporate->{$incrementField});
        $digits = min(12, max(1, (int) $corporate->{$digitsField}));
        $existingCodes = $this->existingCodes($corporate, $type);
        $nextFromExistingCodes = $this->nextAfterExistingCodes(
            $start,
            $increment,
            $prefix,
            $digits,
            $existingCodes,
        );

        // Older trip references were generated per booking and are not stored as
        // company-wide codes. Reserve one slot per existing unnumbered trip while
        // leaving every historical reference as it is.
        if ($isItem) {
            $legacyTripCount = DB::table('booking_items')
                ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                ->where('bookings.corporate_account_id', $corporate->id)
                ->whereNull('booking_items.item_code')
                ->count();
            $nextFromExistingCodes = max(
                $nextFromExistingCodes,
                $start + ($legacyTripCount * $increment),
            );
        }

        $sequence = DB::table('corporate_reference_sequences')
            ->where('corporate_id', $corporate->id)
            ->where('reference_type', $type)
            ->lockForUpdate()
            ->first();
        $nextNumber = max($nextFromExistingCodes, (int) ($sequence->next_number ?? $start));

        // The admin form's starting number reflects where this company's next
        // sequence begins after its existing records.
        DB::table('corporates')->where('id', $corporate->id)->update([
            $startField => $nextNumber,
            'updated_at' => now(),
        ]);
        $corporate->setAttribute($startField, $nextNumber);

        DB::table('corporate_reference_sequences')->updateOrInsert(
            ['corporate_id' => $corporate->id, 'reference_type' => $type],
            [
                'next_number' => $nextNumber,
                'created_at' => $sequence->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
    }

    /** @return array<string, bool> */
    private function existingCodes(Corporate $corporate, string $type): array
    {
        $codes = $type === 'item'
            ? DB::table('booking_items')
                ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                ->where('bookings.corporate_account_id', $corporate->id)
                ->whereNotNull('booking_items.item_code')
                ->pluck('booking_items.item_code')
            : DB::table('bookings')
                ->where('corporate_account_id', $corporate->id)
                ->whereNotNull('booking_number')
                ->pluck('booking_number');

        return array_fill_keys($codes->map(fn ($code) => (string) $code)->all(), true);
    }

    /** @param array<string, bool> $existingCodes */
    private function nextAfterExistingCodes(
        int $number,
        int $increment,
        string $prefix,
        int $digits,
        array $existingCodes,
    ): int {
        $largestUsedNumber = 0;
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
        foreach (array_keys($existingCodes) as $code) {
            if (preg_match($pattern, $code, $matches)) {
                $largestUsedNumber = max($largestUsedNumber, (int) $matches[1]);
            }
        }

        if ($largestUsedNumber >= $number) {
            $number += (intdiv($largestUsedNumber - $number, $increment) + 1) * $increment;
        }

        while (isset($existingCodes[$prefix . str_pad((string) $number, $digits, '0', STR_PAD_LEFT)])) {
            $number += $increment;
        }

        return $number;
    }
}
