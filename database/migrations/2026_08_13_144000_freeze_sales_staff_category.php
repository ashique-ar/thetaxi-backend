<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_profiles', function (Blueprint $table) {
            $table->string('staff_category_snapshot', 100)->nullable()->after('staff_id');
            $table->index(['company_id', 'staff_category_snapshot', 'status'], 'sales_profile_staff_category_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('sales_profiles')->whereNotNull('staff_category_snapshot')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile Sales Profile Staff-category snapshots first.');
        }
        Schema::table('sales_profiles', function (Blueprint $table) {
            $table->dropIndex('sales_profile_staff_category_idx');
            $table->dropColumn('staff_category_snapshot');
        });
    }
};
