<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table): void {
            $table->json('workflow_config')->nullable();
        });
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->json('workflow_config_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table): void {
            $table->dropColumn('workflow_config');
        });
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->dropColumn('workflow_config_snapshot');
        });
    }
};
