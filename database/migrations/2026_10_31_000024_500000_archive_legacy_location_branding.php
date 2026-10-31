<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        $columns = array_values(array_filter(
            ['brand_name', 'domain', 'logo'],
            fn (string $column): bool => Schema::hasColumn('companies', $column),
        ));
        if ($columns === []) {
            return;
        }

        // Historical evidence only; these values must never become routing or tenant configuration.
        if (! Schema::hasTable('legacy_location_branding_archive')) {
            Schema::create('legacy_location_branding_archive', function (Blueprint $table): void {
                $table->uuid('source_record_id')->primary();
                $table->json('branding_values');
                $table->timestamp('archived_at');
            });
        }

        DB::transaction(function () use ($columns): void {
            $rows = DB::table('companies')->select(array_merge(['id'], $columns))
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    if ($row->{$column} !== null && $row->{$column} !== '') {
                        $values[$column] = $row->{$column};
                    }
                }
                if ($values === []) {
                    continue;
                }

                $existing = DB::table('legacy_location_branding_archive')
                    ->where('source_record_id', $row->id)->first();
                if ($existing) {
                    $archived = json_decode($existing->branding_values, true, 512, JSON_THROW_ON_ERROR);
                    foreach ($values as $column => $value) {
                        if (! array_key_exists($column, $archived) || $archived[$column] !== $value) {
                            throw new RuntimeException("Legacy branding archive differs for source record {$row->id}; refusing to overwrite preserved values.");
                        }
                    }
                } else {
                    DB::table('legacy_location_branding_archive')->insert([
                        'source_record_id' => $row->id,
                        'branding_values' => json_encode($values, JSON_THROW_ON_ERROR),
                        'archived_at' => now(),
                    ]);
                }

                DB::table('companies')->where('id', $row->id)->update(array_fill_keys($columns, null));
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserved legacy branding cannot be restored as tenant configuration.');
    }
};
