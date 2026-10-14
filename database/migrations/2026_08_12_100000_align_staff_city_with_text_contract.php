<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('city', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE staff ALTER COLUMN city TYPE uuid USING NULLIF(city, \'\')::uuid');

            return;
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->uuid('city')->nullable()->change();
        });
    }
};
