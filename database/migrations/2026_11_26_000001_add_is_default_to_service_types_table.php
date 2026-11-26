<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            if (!Schema::hasColumn('service_types', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            if (Schema::hasColumn('service_types', 'is_default')) {
                $table->dropColumn('is_default');
            }
        });
    }
};
