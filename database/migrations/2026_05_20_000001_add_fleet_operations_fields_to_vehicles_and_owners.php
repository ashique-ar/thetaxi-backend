<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('ownership_type')->default('company_owned')->after('company_id')->index();
            $table->string('usage_type')->default('standard_fleet')->after('ownership_type')->index();
            $table->string('payment_model')->default('none')->after('usage_type')->index();
            $table->string('assignment_policy')->default('any_driver')->after('payment_model')->index();
            $table->decimal('monthly_payment_commitment', 12, 2)->nullable()->after('assignment_policy');
            $table->decimal('monthly_mileage_limit', 10, 2)->nullable()->after('monthly_payment_commitment');
            $table->decimal('excess_mileage_rate', 10, 2)->nullable()->after('monthly_mileage_limit');
        });

        Schema::table('vehicle_owners', function (Blueprint $table) {
            $table->uuid('driver_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_owners', function (Blueprint $table) {
            $table->dropIndex(['driver_id']);
            $table->dropColumn('driver_id');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['ownership_type']);
            $table->dropIndex(['usage_type']);
            $table->dropIndex(['payment_model']);
            $table->dropIndex(['assignment_policy']);
            $table->dropColumn([
                'ownership_type',
                'usage_type',
                'payment_model',
                'assignment_policy',
                'monthly_payment_commitment',
                'monthly_mileage_limit',
                'excess_mileage_rate',
            ]);
        });
    }
};
