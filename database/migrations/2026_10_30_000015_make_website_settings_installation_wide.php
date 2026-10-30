<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_settings') || ! Schema::hasColumn('website_settings', 'company_id')) {
            return;
        }

        $settings = DB::table('website_settings')->whereNotNull('type')->whereNull('deleted_at')
            ->orderByDesc('updated_at')->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('type');

        foreach ($settings as $type => $rows) {
            // Keep the prior global value; when only scoped values exist, keep the latest edit.
            $keep = $rows->firstWhere('company_id', null) ?? $rows->first();
            if ($keep->company_id !== null) {
                DB::table('website_settings')->where('id', $keep->id)->update(['company_id' => null]);
            }
            DB::table('website_settings')->where('type', $type)->whereNull('deleted_at')
                ->where('id', '<>', $keep->id)->delete();
        }

        foreach (Schema::getForeignKeys('website_settings') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('website_settings', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes('website_settings') as $index) {
            if ($index['type'] !== 'primary' && in_array('company_id', $index['columns'], true)) {
                $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
                Schema::table('website_settings', fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
            }
        }
        Schema::table('website_settings', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        if (Schema::hasTable('website_settings') && ! Schema::hasColumn('website_settings', 'company_id')) {
            Schema::table('website_settings', fn (Blueprint $table) => $table->uuid('company_id')->nullable()->index());
        }
    }
};
