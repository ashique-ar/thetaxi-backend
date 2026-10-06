<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('wialon_integrations', 'group_mappings')) {
            Schema::table('wialon_integrations', fn (Blueprint $table) => $table->dropColumn('group_mappings'));
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('wialon_integrations', 'group_mappings')) {
            Schema::table('wialon_integrations', fn (Blueprint $table) => $table->json('group_mappings')->nullable());
        }
    }
};
