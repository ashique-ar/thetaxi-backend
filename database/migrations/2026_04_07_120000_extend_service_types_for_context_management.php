<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            if (!Schema::hasColumn('service_types', 'context')) {
                $table->string('context', 50)->default('public')->after('name');
            }

            if (!Schema::hasColumn('service_types', 'owner_type')) {
                $table->string('owner_type', 100)->default('')->after('context');
            }

            if (!Schema::hasColumn('service_types', 'owner_id')) {
                $table->string('owner_id', 100)->default('')->after('owner_type');
            }

            if (!Schema::hasColumn('service_types', 'parent_service_type_id')) {
                $table->uuid('parent_service_type_id')->nullable()->after('owner_id');
            }
        });

        DB::table('service_types')
            ->whereNull('context')
            ->update([
                'context' => 'public',
                'owner_type' => '',
                'owner_id' => '',
            ]);

        Schema::table('service_types', function (Blueprint $table) {
            try {
                $table->dropUnique('service_types_code_unique');
            } catch (\Throwable $e) {
                // Ignore when the legacy index does not exist in this environment.
            }

            try {
                // $table->dropUnique('service_types_slug_unique');
            } catch (\Throwable $e) {
                // Ignore when the legacy index does not exist in this environment.
            }

            $table->index(['context', 'owner_type', 'owner_id'], 'service_types_context_owner_idx');
            $table->index('parent_service_type_id', 'service_types_parent_service_type_id_idx');
            $table->unique(['code', 'context', 'owner_type', 'owner_id'], 'service_types_code_scope_unique');
            $table->unique(['slug', 'context', 'owner_type', 'owner_id'], 'service_types_slug_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            try {
                $table->dropUnique('service_types_code_scope_unique');
            } catch (\Throwable $e) {
            }

            try {
                $table->dropUnique('service_types_slug_scope_unique');
            } catch (\Throwable $e) {
            }

            try {
                $table->dropIndex('service_types_context_owner_idx');
            } catch (\Throwable $e) {
            }

            try {
                $table->dropIndex('service_types_parent_service_type_id_idx');
            } catch (\Throwable $e) {
            }

            if (Schema::hasColumn('service_types', 'parent_service_type_id')) {
                $table->dropColumn('parent_service_type_id');
            }

            if (Schema::hasColumn('service_types', 'owner_id')) {
                $table->dropColumn('owner_id');
            }

            if (Schema::hasColumn('service_types', 'owner_type')) {
                $table->dropColumn('owner_type');
            }

            if (Schema::hasColumn('service_types', 'context')) {
                $table->dropColumn('context');
            }

            $table->unique('code', 'service_types_code_unique');
            $table->unique('slug', 'service_types_slug_unique');
        });
    }
};
