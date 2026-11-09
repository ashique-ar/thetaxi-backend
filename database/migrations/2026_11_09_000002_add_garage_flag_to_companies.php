<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql', 'sqlsrv', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver for garage uniqueness.');
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_garage')->default(false);
        });
        if (Schema::hasTable('garage_location')) {
            $garageId = DB::table('garage_location')->where('id', 1)->value('company_id');
            if ($garageId) DB::table('companies')->where('id', $garageId)->update(['is_garage' => true]);
            Schema::drop('garage_location');
        }
        if ($driver === 'mysql') {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedTinyInteger('garage_guard')->nullable()
                    ->storedAs('CASE WHEN is_garage = 1 AND deleted_at IS NULL THEN 1 ELSE NULL END');
                $table->unique('garage_guard', 'companies_one_garage_unique');
            });
        } elseif ($driver === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX companies_one_garage_unique ON companies (is_garage) WHERE is_garage = 1 AND deleted_at IS NULL');
        } else {
            DB::statement('CREATE UNIQUE INDEX companies_one_garage_unique ON companies (is_garage) WHERE is_garage = true AND deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropUnique('companies_one_garage_unique');
                $table->dropColumn('garage_guard');
            });
        } elseif ($driver === 'sqlsrv') {
            DB::statement('DROP INDEX companies_one_garage_unique ON companies');
        } else {
            DB::statement('DROP INDEX IF EXISTS companies_one_garage_unique');
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('is_garage');
        });
    }
};
