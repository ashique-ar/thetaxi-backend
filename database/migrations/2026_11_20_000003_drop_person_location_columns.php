<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = ['customers' => ['city', 'state_id', 'country_id', 'country', 'postal_code'], 'staff' => ['city', 'state_id', 'country_id', 'postal_code'], 'drivers' => ['city', 'state_id', 'country_id', 'postal_code']];

    public function up(): void
    {
        foreach ($this->columns as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) continue;
                    match ($column) {
                        'city' => $table === 'staff' ? $blueprint->uuid($column)->nullable() : $blueprint->string($column)->nullable(),
                        'country' => $blueprint->string($column)->nullable(),
                        'country_id', 'state_id' => $blueprint->uuid($column)->nullable(),
                        'postal_code' => $blueprint->string($column, 20)->nullable(),
                    };
                }
            });
            $type = match ($table) {
                'customers' => \App\Models\Customer::class,
                'staff' => \App\Models\Staff::class,
                default => \App\Models\Driver\Driver::class,
            };
            DB::table('addresses')->where('addressable_type', $type)->where('is_default', true)->orderBy('id')->chunkById(500, function ($addresses) use ($table): void {
                foreach ($addresses as $address) {
                    $values = array_intersect_key((array) $address, array_flip($this->columns[$table]));
                    if ($table === 'customers') $values['country'] = $address->country_name ?: DB::table('countries')->where('id', $address->country_id)->value('name');
                    DB::table($table)->where('id', $address->addressable_id)->update($values);
                }
            });
        }
    }
};
