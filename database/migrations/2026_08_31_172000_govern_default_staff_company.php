<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlsrv', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver for company default uniqueness.');
        }

        $headOfficeId = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower('Casons Rent A Car - Head Office')])
            ->value('id');

        $defaultId = $headOfficeId ?: DB::table('companies')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_default', true)
            ->orderBy('created_at')
            ->value('id');

        $defaultId ??= DB::table('companies')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('created_at')
            ->value('id');

        if ($defaultId) {
            DB::table('companies')->whereNull('deleted_at')->update(['is_default' => false]);
            DB::table('companies')->where('id', $defaultId)->update(['is_default' => true]);
            DB::table('staff')
                ->whereNull('deleted_at')
                ->whereNull('company_id')
                ->update(['company_id' => $defaultId, 'updated_at' => now()]);
        }

        if ($driver === 'mysql') {
            Schema::table('companies', function (Blueprint $table): void {
                $table->unsignedTinyInteger('active_default_guard')->nullable()
                    ->storedAs('CASE WHEN is_default = 1 AND deleted_at IS NULL THEN 1 ELSE NULL END');
                $table->unique('active_default_guard', 'companies_one_active_default_unique');
            });
        } elseif ($driver === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX companies_one_active_default_unique ON companies (is_default) WHERE is_default = 1 AND deleted_at IS NULL');
        } else {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS companies_one_active_default_unique ON companies ((is_default)) WHERE is_default = true AND deleted_at IS NULL');
        }
    }
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropUnique('companies_one_active_default_unique');
                $table->dropColumn('active_default_guard');
            });
        } elseif ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX companies_one_active_default_unique ON companies');
        } else {
            DB::statement('DROP INDEX IF EXISTS companies_one_active_default_unique');
        }
    }
};
