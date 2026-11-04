<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->index(['trip_phase', 'trip_completed_at'], 'driver_assignments_trip_completion_idx');
        });
    }

    public function down(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->dropIndex('driver_assignments_trip_completion_idx');
        });
    }
};
