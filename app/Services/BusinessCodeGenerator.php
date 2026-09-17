<?php

namespace App\Services;

use App\Models\BusinessNumberSequence;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

class BusinessCodeGenerator
{
    private const CONFIG = [
        'customer' => ['model' => Customer::class, 'prefix' => 'CUST', 'digits' => 3],
        'staff' => ['model' => Staff::class, 'prefix' => 'STF', 'digits' => 6],
        'driver' => ['model' => Driver::class, 'prefix' => 'DRV', 'digits' => 6],
    ];

    public function generate(string $entity): string
    {
        $config = self::CONFIG[$entity] ?? throw new \InvalidArgumentException("Unsupported code entity: {$entity}");

        return DB::transaction(function () use ($entity, $config) {
            $prefix = BusinessSetting::getSetting("{$entity}_code_prefix") ?? $config['prefix'];
            $suffix = BusinessSetting::getSetting("{$entity}_code_suffix") ?? '';
            $digits = max(1, min(12, (int) (BusinessSetting::getSetting("{$entity}_code_digits") ?? $config['digits'])));
            $start = max(1, (int) (BusinessSetting::getSetting("{$entity}_code_start_number") ?? 1));

            BusinessNumberSequence::query()->firstOrCreate(
                ['entity' => $entity],
                ['next_number' => $start]
            );
            $sequence = BusinessNumberSequence::query()->lockForUpdate()->findOrFail($entity);
            $number = max($start, (int) $sequence->next_number);

            do {
                $code = $prefix . str_pad((string) $number, $digits, '0', STR_PAD_LEFT) . $suffix;
                $number++;
            } while ($config['model']::withTrashed()->where('code', $code)->exists());

            $sequence->update(['next_number' => $number]);

            return $code;
        }, 3);
    }
}
