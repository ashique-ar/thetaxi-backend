<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const SEQUENCE_KEY = 'installation';

    public function up(): void
    {
        if (Schema::hasTable('hr_employee_number_sequences') && Schema::hasColumn('hr_employee_number_sequences', 'company_id')) {
            $sequences = DB::table('hr_employee_number_sequences')->orderBy('created_at')->orderBy('id')->get();
            if ($sequences->isNotEmpty()) {
                $formats = $sequences->map(fn ($row) => json_encode([
                    $row->template,
                    $row->prefix,
                    $row->suffix,
                    $row->start_value,
                    $row->padding,
                    $row->status,
                ], JSON_THROW_ON_ERROR))->unique();
                if ($formats->count() > 1) {
                throw new RuntimeException('Employee-number sequence formats differ across former company partitions. Reconcile them to one format before applying the installation-wide migration.');
                }
            }

            Schema::table('hr_employee_number_sequences', function (Blueprint $table): void {
                $table->string('installation_key', 32)->nullable()->unique();
            });
            if ($sequences->isNotEmpty()) {
                $selected = $sequences->first();
                $nextValue = (int) $sequences->max('next_value');
                DB::table('hr_employee_number_sequences')->where('id', '!=', $selected->id)->delete();
                DB::table('hr_employee_number_sequences')->where('id', $selected->id)->update([
                    'installation_key' => self::SEQUENCE_KEY,
                    'next_value' => $nextValue,
                    'updated_at' => now(),
                ]);
            }

            $companyIndex = collect(Schema::getIndexes('hr_employee_number_sequences'))
                ->first(fn (array $index): bool => $index['name'] === 'hr_employee_number_sequences_company_id_unique');
            if ($companyIndex) {
                TenantIndexDropper::drop('hr_employee_number_sequences', $companyIndex);
            }

            Schema::table('hr_employee_number_sequences', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_id');
            });
        }

        if (Schema::hasTable('hr_employee_number_aliases') && Schema::hasColumn('hr_employee_number_aliases', 'company_id')) {
            Schema::table('hr_employee_number_aliases', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_employee_number_sequences') && ! Schema::hasColumn('hr_employee_number_sequences', 'company_id')) {
            Schema::table('hr_employee_number_sequences', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->unique()->constrained('companies')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('hr_employee_number_aliases') && ! Schema::hasColumn('hr_employee_number_aliases', 'company_id')) {
            Schema::table('hr_employee_number_aliases', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            });
        }

        if (Schema::hasTable('hr_employee_number_sequences') && Schema::hasColumn('hr_employee_number_sequences', 'installation_key')) {
            $installationIndex = collect(Schema::getIndexes('hr_employee_number_sequences'))
                ->first(fn (array $index): bool => $index['name'] === 'hr_employee_number_sequences_installation_key_unique');
            if ($installationIndex) {
                TenantIndexDropper::drop('hr_employee_number_sequences', $installationIndex);
            }

            Schema::table('hr_employee_number_sequences', function (Blueprint $table): void {
                $table->dropColumn('installation_key');
            });
        }
    }
};
