<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employment_assignments')) return;

        if (Schema::hasColumn('hr_employment_assignments', 'snapshot')) {
            foreach (DB::table('hr_employment_assignments')->get(['id', 'snapshot']) as $assignment) {
                $snapshot = json_decode($assignment->snapshot, true, 512, JSON_THROW_ON_ERROR);
                unset($snapshot['company_id']);
                DB::table('hr_employment_assignments')->where('id', $assignment->id)->update([
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                ]);
            }
        }

        if (! Schema::hasColumn('hr_employment_assignments', 'company_id')) return;
        foreach (Schema::getForeignKeys('hr_employment_assignments') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('hr_employment_assignments', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes('hr_employment_assignments') as $index) {
            if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) continue;
            $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
            Schema::table('hr_employment_assignments', fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
        }
        Schema::table('hr_employment_assignments', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Employment assignment tenant company data cannot be restored without reintroducing company-based staff placement.');
    }
};
