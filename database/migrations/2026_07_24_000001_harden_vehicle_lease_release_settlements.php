<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_lease_releases', function (Blueprint $table) {
            $table->string('settlement_direction', 40)->nullable()->after('settlement_status');
            $table->decimal('settlement_amount', 14, 2)->nullable()->after('settlement_direction');
            $table->string('settlement_method', 40)->nullable()->after('settlement_amount');
            $table->uuid('settlement_idempotency_key')->nullable()->unique()->after('settlement_reference');
        });

    }

    public function down(): void
    {
        Schema::table('vehicle_lease_releases', function (Blueprint $table) {
            $table->dropUnique(['settlement_idempotency_key']);
            $table->dropColumn([
                'settlement_direction',
                'settlement_amount',
                'settlement_method',
                'settlement_idempotency_key',
            ]);
        });
    }
};
