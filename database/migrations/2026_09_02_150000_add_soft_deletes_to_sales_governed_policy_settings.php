<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Companion fix for 2026_09_01_180000_create_sales_governed_policy_settings.php,
// which creates 3 tables for models that extend BaseModel (SoftDeletes trait)
// but never added the deleted_at column itself. Any query against those tables
// (including PeopleCoreController::index(), hit by every Employee Directory
// load) crashes with "column deleted_at does not exist" on databases where
// that migration already ran. Guarded with hasColumn so it's also safe to run
// on a fresh install where the create migration was fixed directly.
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'sales_policy_settings',
            'sales_staff_category_definitions',
            'sales_company_feature_settings',
        ] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'sales_policy_settings',
            'sales_staff_category_definitions',
            'sales_company_feature_settings',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropSoftDeletes();
                });
            }
        }
    }
};
