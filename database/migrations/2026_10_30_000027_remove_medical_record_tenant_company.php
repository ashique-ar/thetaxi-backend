<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_records') && Schema::hasColumn('medical_records', 'company_id')) {
            Schema::table('medical_records', function (Blueprint $table): void {
                $table->dropForeign('medical_records_company_id_foreign');
                $table->dropUnique('medical_records_company_id_record_number_unique');
                $table->dropIndex('medical_records_company_id_subject_type_subject_id_index');
                $table->dropIndex('medical_records_company_id_status_valid_until_index');
                $table->dropColumn('company_id');
            });
        }

        if (Schema::hasTable('medical_categories') && Schema::hasColumn('medical_categories', 'company_id')) {
            Schema::table('medical_categories', function (Blueprint $table): void {
                $table->dropForeign('medical_categories_company_id_foreign');
                $table->dropUnique('medical_categories_company_id_code_unique');
                $table->dropColumn('company_id');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Medical tenant company fields cannot be restored without reintroducing tenant scope.');
    }
};
