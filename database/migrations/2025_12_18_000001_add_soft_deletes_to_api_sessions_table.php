<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('api_sessions', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('api_sessions', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
