<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
            $table->string('display_unit', 32)->nullable()->after('common_rate_type');
        });

        DB::table('vehicle_pricing_common_rate_definitions')
            ->select(['id', 'code', 'name', 'common_rate_type'])
            ->orderBy('id')
            ->chunk(200, function ($definitions): void {
                foreach ($definitions as $definition) {
                    DB::table('vehicle_pricing_common_rate_definitions')
                        ->where('id', $definition->id)
                        ->update(['display_unit' => $this->inferDisplayUnit($definition)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
            $table->dropColumn('display_unit');
        });
    }

    private function inferDisplayUnit(object $definition): string
    {
        $identifier = strtolower(preg_replace(
            '/[^a-z0-9]+/i',
            '_',
            trim(($definition->code ?? '').' '.($definition->name ?? ''))
        ));

        $quantityTerms = ['included', 'include', 'minimum', 'maximum', 'max', 'free', 'allowed', 'allowance', 'limit', 'duration', 'distance', 'quantity'];
        $isQuantity = collect($quantityTerms)->contains(fn (string $term) => str_contains($identifier, $term));
        $isMonetary = collect(['rate', 'charge', 'fare', 'price', 'cost'])
            ->contains(fn (string $term) => str_contains($identifier, $term));

        if (!$isMonetary && $isQuantity && preg_match('/(^|_)(km|kilometre|kilometer)(_|$)/', $identifier)) {
            return str_contains($identifier, 'per_day') ? 'km/day' : 'km';
        }
        if (!$isMonetary && $isQuantity && preg_match('/(^|_)(minute|minutes|min)(_|$)/', $identifier)) {
            return 'min';
        }
        if (!$isMonetary && $isQuantity && preg_match('/(^|_)(hour|hours)(_|$)/', $identifier)) {
            return 'hr';
        }
        if (!$isMonetary && $isQuantity && preg_match('/(^|_)(day|days)(_|$)/', $identifier)) {
            return 'day';
        }

        return match ($definition->common_rate_type) {
            'per_hour' => 'LKR/hr',
            'per_minute' => 'LKR/min',
            'per_day' => 'LKR/day',
            'per_km' => 'LKR/km',
            'percentage' => '%',
            default => 'LKR',
        };
    }
};
