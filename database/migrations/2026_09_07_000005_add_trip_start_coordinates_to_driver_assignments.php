<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->decimal('trip_start_latitude', 10, 8)->nullable()->after('trip_started_at');
            $table->decimal('trip_start_longitude', 11, 8)->nullable()->after('trip_start_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->dropColumn(['trip_start_latitude', 'trip_start_longitude']);
        });
    }
};
