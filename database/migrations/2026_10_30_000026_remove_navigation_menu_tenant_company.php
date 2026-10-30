<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('navigation_menus') || ! Schema::hasColumn('navigation_menus', 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys('navigation_menus') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('navigation_menus', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes('navigation_menus') as $index) {
            if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }
            $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
            Schema::table('navigation_menus', fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
        }

        Schema::table('navigation_menus', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        if (Schema::hasTable('navigation_menus') && ! Schema::hasColumn('navigation_menus', 'company_id')) {
            Schema::table('navigation_menus', fn (Blueprint $table) => $table->uuid('company_id')->nullable()->index());
        }
    }
};
