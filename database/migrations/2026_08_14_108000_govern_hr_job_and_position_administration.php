<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_job_families', 'hr_job_grades', 'hr_designations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('version')->default(1);
                $table->date('effective_from')->nullable();
                $table->date('effective_until')->nullable();
                $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            });
        }

        Schema::table('hr_positions', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dropUnique('hr_positions_position_number_unique');
            $table->unique(['company_id', 'position_number'], 'hr_positions_company_number_unique');
            $table->index(['company_id', 'status', 'effective_from', 'effective_until'], 'hr_positions_company_status_effective_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_organization_change_events') && DB::table('hr_organization_change_events')
            ->whereIn('aggregate_type', ['job_family', 'job_grade', 'designation', 'position'])->exists()) {
            throw new LogicException('Refusing to remove governed HR job or position history. Disable the feature without deleting retained events.');
        }

        $duplicateNumber = DB::table('hr_positions')
            ->select('position_number')
            ->groupBy('position_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateNumber) {
            throw new LogicException('Refusing rollback because legal entities now contain duplicate position numbers that the legacy global constraint cannot represent.');
        }

        Schema::table('hr_positions', function (Blueprint $table) {
            $table->dropIndex('hr_positions_company_status_effective_index');
            $table->dropUnique('hr_positions_company_number_unique');
            $table->unique('position_number', 'hr_positions_position_number_unique');
            $table->dropConstrainedForeignId('updated_user_id');
            $table->dropColumn('version');
        });

        foreach (['hr_designations', 'hr_job_grades', 'hr_job_families'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_user_id');
                $table->dropColumn(['version', 'effective_from', 'effective_until']);
            });
        }
    }
};
