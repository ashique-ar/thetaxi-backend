<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_records') && Schema::hasColumn('medical_records', 'company_id')) {
            foreach (Schema::getForeignKeys('medical_records') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('medical_records', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('medical_records') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('medical_records', $index);
                }
            }
            Schema::table('medical_records', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        if (Schema::hasTable('medical_categories') && Schema::hasColumn('medical_categories', 'company_id')) {
            foreach (Schema::getForeignKeys('medical_categories') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('medical_categories', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('medical_categories') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('medical_categories', $index);
                }
            }
            Schema::table('medical_categories', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Medical tenant company fields cannot be restored without reintroducing tenant scope.');
    }
};
