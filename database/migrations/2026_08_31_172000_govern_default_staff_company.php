<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS companies_one_active_default_unique ON companies ((is_default)) WHERE is_default = true AND deleted_at IS NULL');
    }
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS companies_one_active_default_unique');
    }
};
