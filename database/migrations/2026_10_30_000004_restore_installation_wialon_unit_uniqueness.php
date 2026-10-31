<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('vehicles')->select('wialon_unit_id')->whereNotNull('wialon_unit_id')
            ->groupBy('wialon_unit_id')->havingRaw('COUNT(*) > 1')->get();
        if ($duplicates->isNotEmpty()) {
                throw new RuntimeException('Resolve duplicate Wialon unit links across former company partitions before applying installation-wide fleet uniqueness.');
        }

        if (Schema::hasIndex('vehicles', 'vehicles_company_wialon_unit_unique', 'unique')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropUnique('vehicles_company_wialon_unit_unique'));
        }
        if (! Schema::hasIndex('vehicles', 'vehicles_wialon_unit_id_unique', 'unique')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->unique('wialon_unit_id', 'vehicles_wialon_unit_id_unique'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('vehicles', 'vehicles_wialon_unit_id_unique', 'unique')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropUnique('vehicles_wialon_unit_id_unique'));
        }
        if (! Schema::hasIndex('vehicles', 'vehicles_company_wialon_unit_unique', 'unique')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->unique(['company_id', 'wialon_unit_id'], 'vehicles_company_wialon_unit_unique'));
        }
    }
};
