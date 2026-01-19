<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('terms_and_conditions', function (Blueprint $table) {
            $table->uuid('service_type_id')->nullable()->after('content');
            $table->index('service_type_id');
        });

        // Try to migrate existing enum string values to service_types.id if possible by matching code
        if (Schema::hasTable('service_types')) {
            DB::statement("
                UPDATE terms_and_conditions AS t
                SET service_type_id = s.id
                FROM service_types s
                WHERE t.service_type IS NOT NULL
                  AND (s.code = t.service_type OR s.slug = t.service_type)
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terms_and_conditions', function (Blueprint $table) {
            if (Schema::hasColumn('terms_and_conditions', 'service_type_id')) {
                $table->dropForeign(['service_type_id']);
                $table->dropIndex(['service_type_id']);
                $table->dropColumn('service_type_id');
            }
        });
    }
};