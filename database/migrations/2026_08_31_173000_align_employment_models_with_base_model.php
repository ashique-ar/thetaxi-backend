<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_employment_spells', function (Blueprint $table) {
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        Schema::table('hr_employment_assignments', function (Blueprint $table) {
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_employment_spells')->where(function ($query): void {
            $query->whereNotNull('updated_user_id')->orWhereNotNull('deleted_at');
        })->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile employment spell tracking and deletion evidence first.');
        }
        if (DB::table('hr_employment_assignments')->where(function ($query): void {
            $query->whereNotNull('created_user_id')->orWhereNotNull('updated_user_id')->orWhereNotNull('deleted_at');
        })->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile employment assignment tracking and deletion evidence first.');
        }

        Schema::table('hr_employment_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_user_id');
            $table->dropConstrainedForeignId('created_user_id');
            $table->dropSoftDeletes();
        });

        Schema::table('hr_employment_spells', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_user_id');
            $table->dropSoftDeletes();
        });
    }
};
