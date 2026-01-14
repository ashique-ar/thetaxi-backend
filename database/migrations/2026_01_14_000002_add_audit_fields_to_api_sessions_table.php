<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('api_sessions', 'created_user_id')) {
                $table->uuid('created_user_id')->nullable()->index();
            }
            if (!Schema::hasColumn('api_sessions', 'updated_user_id')) {
                $table->uuid('updated_user_id')->nullable()->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('api_sessions', 'updated_user_id')) {
                $table->dropIndex(['updated_user_id']);
                $table->dropColumn('updated_user_id');
            }
            if (Schema::hasColumn('api_sessions', 'created_user_id')) {
                $table->dropIndex(['created_user_id']);
                $table->dropColumn('created_user_id');
            }
        });
    }
};
