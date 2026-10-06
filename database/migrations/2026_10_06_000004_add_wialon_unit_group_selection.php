<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wialon_integrations', function (Blueprint $table) {
            $table->json('group_ids')->nullable()->after('resource_ids');
        });
    }

    public function down(): void
    {
        Schema::table('wialon_integrations', fn (Blueprint $table) => $table->dropColumn('group_ids'));
    }
};
