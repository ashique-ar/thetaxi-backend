<?php

namespace App\Services;

use App\Models\Corporate\Corporate;
use Illuminate\Support\Facades\DB;

class CorporateReferenceNumberService
{
    public function next(string $corporateId, string $type): string
    {
        return DB::transaction(function () use ($corporateId, $type): string {
            $corporate = Corporate::whereKey($corporateId)->lockForUpdate()->firstOrFail();
            $isItem = $type === 'item';
            $prefix = (string) ($isItem ? $corporate->booking_item_number_prefix : $corporate->booking_number_prefix);
            $start = max(1, (int) ($isItem ? $corporate->booking_item_number_start : $corporate->booking_number_start));
            $increment = max(1, (int) ($isItem ? $corporate->booking_item_number_increment : $corporate->booking_number_increment));
            $digits = min(12, max(1, (int) ($isItem ? $corporate->booking_item_number_digits : $corporate->booking_number_digits)));

            $sequence = DB::table('corporate_reference_sequences')
                ->where('corporate_id', $corporateId)
                ->where('reference_type', $type)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                DB::table('corporate_reference_sequences')->insert([
                    'corporate_id' => $corporateId,
                    'reference_type' => $type,
                    'next_number' => $start,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $number = $start;
            } else {
                $number = max($start, (int) $sequence->next_number);
            }

            $existingReferences = $isItem
                ? DB::table('booking_items')
                    ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                    ->where('bookings.corporate_account_id', $corporateId)
                    ->where('booking_items.item_code', 'like', $prefix . '%')
                    ->pluck('booking_items.item_code')
                : DB::table('bookings')
                    ->where('corporate_account_id', $corporateId)
                    ->where('booking_number', 'like', $prefix . '%')
                    ->pluck('booking_number');
            $existingReferences = array_fill_keys($existingReferences->all(), true);

            do {
                $candidate = $prefix . str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
                if (! isset($existingReferences[$candidate])) {
                    break;
                }
                $number += $increment;
            } while (true);

            $nextNumber = $number + $increment;
            DB::table('corporate_reference_sequences')->updateOrInsert(
                ['corporate_id' => $corporateId, 'reference_type' => $type],
                ['next_number' => $nextNumber, 'updated_at' => now(), 'created_at' => $sequence?->created_at ?? now()]
            );

            return $candidate;
        });
    }
}
