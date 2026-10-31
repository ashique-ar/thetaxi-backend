<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_attendance_periods') || ! Schema::hasColumn('hr_attendance_periods', 'company_id')) {
            return;
        }

        $overlap = DB::table('hr_attendance_periods as first_period')
            ->join('hr_attendance_periods as second_period', function ($join): void {
                $join->on('first_period.id', '<', 'second_period.id')
                    ->on('first_period.period_start', '<=', 'second_period.period_end')
                    ->on('first_period.period_end', '>=', 'second_period.period_start');
            })
            ->first(['first_period.id as first_id', 'second_period.id as second_id']);

        if ($overlap) {
            throw new RuntimeException("Attendance periods {$overlap->first_id} and {$overlap->second_id} overlap across company scopes. Reconcile them before removing the tenant column.");
        }

        $foreignKey = collect(Schema::getForeignKeys('hr_attendance_periods'))
            ->first(fn (array $key): bool => in_array('company_id', $key['columns'], true));
        if ($foreignKey) {
            Schema::table('hr_attendance_periods', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
        }

        foreach (Schema::getIndexes('hr_attendance_periods') as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop('hr_attendance_periods', $index);
            }
        }

        Schema::table('hr_attendance_periods', function (Blueprint $table): void {
            $table->dropColumn('company_id');
            $table->unique(['period_start', 'period_end'], 'hr_attendance_period_range_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_attendance_periods') || Schema::hasColumn('hr_attendance_periods', 'company_id')) {
            return;
        }

        Schema::table('hr_attendance_periods', function (Blueprint $table): void {
            $table->dropUnique('hr_attendance_period_range_unique');
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'period_start', 'period_end'], 'hr_attendance_period_company_range_idx');
        });
    }
};
