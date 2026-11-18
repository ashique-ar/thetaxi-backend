<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_maintenance_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('next_due_mileage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_maintenance_schedules', function (Blueprint $table): void {
            $table->dropColumn('next_due_mileage');
        });
    }
};
