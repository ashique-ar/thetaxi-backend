<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'vehicle_pricing_slab_definitions',
            'vehicle_group_pricing',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'owner_type')) {
                    $table->string('owner_type', 50)->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'owner_id')) {
                    $table->uuid('owner_id')->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'priority')) {
                    $table->integer('priority')->default(0)->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'vehicle_pricing_slab_definitions',
            'vehicle_group_pricing',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'owner_type')) {
                    $table->dropColumn('owner_type');
                }
                if (Schema::hasColumn($tableName, 'owner_id')) {
                    $table->dropColumn('owner_id');
                }
                if (Schema::hasColumn($tableName, 'priority')) {
                    $table->dropColumn('priority');
                }
            });
        }
    }
};
