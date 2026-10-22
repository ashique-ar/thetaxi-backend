<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['corporate_distance_pricing_policies', 'corporate_service_distance_policies'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('outbound_rate', 20, 4)->nullable()->change();
                $table->decimal('return_rate', 20, 4)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['corporate_distance_pricing_policies', 'corporate_service_distance_policies'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->decimal('outbound_rate', 12, 2)->nullable()->change();
                $table->decimal('return_rate', 12, 2)->nullable()->change();
            });
        }
    }
};
