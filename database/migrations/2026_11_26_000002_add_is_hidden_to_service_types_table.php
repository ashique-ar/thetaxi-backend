<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            if (!Schema::hasColumn('service_types', 'is_portal_hidden')) {
                $table->boolean('is_portal_hidden')->default(false)->after('is_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            if (Schema::hasColumn('service_types', 'is_portal_hidden')) {
                $table->dropColumn('is_portal_hidden');
            }
        });
    }
};
