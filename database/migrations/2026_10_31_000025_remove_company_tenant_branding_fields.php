<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        $populated = [];
        foreach (['brand_name', 'domain', 'logo'] as $column) {
            if (! Schema::hasColumn('companies', $column)) {
                continue;
            }
            $ids = DB::table('companies')->whereNotNull($column)->where($column, '<>', '')
                ->limit(10)->pluck('id')->all();
            if ($ids !== []) {
                $populated[$column] = $ids;
            }
        }
        if ($populated !== []) {
            throw new RuntimeException('Move or classify populated legacy company branding values in the installation-wide website settings before removing these location fields: '.json_encode($populated, JSON_THROW_ON_ERROR));
        }

        foreach (Schema::getIndexes('companies') as $index) {
            if (array_intersect($index['columns'], ['domain'])) {
                TenantIndexDropper::drop('companies', $index);
            }
        }

        $columns = array_values(array_filter(
            ['brand_name', 'domain', 'logo'],
            static fn (string $column): bool => Schema::hasColumn('companies', $column),
        ));
        if ($columns !== []) {
            Schema::table('companies', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Tenant branding fields cannot be restored to location records.');
    }
};
