<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_scope_assignments') || ! Schema::hasColumn('staff_scope_assignments', 'company_id')) return;

        foreach (Schema::getForeignKeys('staff_scope_assignments') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('staff_scope_assignments', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes('staff_scope_assignments') as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop('staff_scope_assignments', $index);
            }
        }

        Schema::table('staff_scope_assignments', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        throw new RuntimeException('Staff team assignments cannot regain company scope.');
    }
};
