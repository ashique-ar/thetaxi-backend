<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->default(0)->after('duration_hours');
        });

        Schema::table('booking_searches', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->nullable()->after('duration_hours');
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->unsignedTinyInteger('default_duration_minutes')->default(0)->after('default_duration_hours')
                ->comment('Minute component from 0 to 59, added to default_duration_hours');
        });

        DB::table('booking_items')->update([
            'duration_minutes' => DB::raw('COALESCE(duration_hours, 0) * 60'),
        ]);
        DB::table('booking_searches')->whereNotNull('duration_hours')->update([
            'duration_minutes' => DB::raw('duration_hours * 60'),
        ]);
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn('default_duration_minutes');
        });
        Schema::table('booking_searches', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
