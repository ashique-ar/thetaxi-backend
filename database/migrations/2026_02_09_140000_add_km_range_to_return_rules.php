<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_package_return_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('service_package_return_rules', 'km_min')) {
                $table->decimal('km_min', 10, 2)->nullable()->after('day_offset_max')
                    ->comment('Minimum kilometers for this rule to apply');
            }
            
            if (!Schema::hasColumn('service_package_return_rules', 'km_max')) {
                $table->decimal('km_max', 10, 2)->nullable()->after('km_min')
                    ->comment('Maximum kilometers for this rule to apply (null = unlimited)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_package_return_rules', function (Blueprint $table) {
            if (Schema::hasColumn('service_package_return_rules', 'km_max')) {
                $table->dropColumn('km_max');
            }
            
            if (Schema::hasColumn('service_package_return_rules', 'km_min')) {
                $table->dropColumn('km_min');
            }
        });
    }
};
