<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique('vehicles_wialon_unit_id_unique');
            $table->unique(['company_id', 'wialon_unit_id'], 'vehicles_company_wialon_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique('vehicles_company_wialon_unit_unique');
            $table->unique('wialon_unit_id', 'vehicles_wialon_unit_id_unique');
        });
    }
};
