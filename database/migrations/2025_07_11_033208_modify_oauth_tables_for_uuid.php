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
        // Modify oauth_access_tokens table to support UUID user_id
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->string('user_id')->nullable()->change();
            $table->index('user_id');
        });

        // Modify oauth_refresh_tokens table if it exists
        if (Schema::hasTable('oauth_refresh_tokens')) {
            Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
                if (Schema::hasColumn('oauth_refresh_tokens', 'user_id')) {
                    $table->string('user_id')->nullable()->change();
                }
            });
        }

        // Modify oauth_auth_codes table if it exists
        if (Schema::hasTable('oauth_auth_codes')) {
            Schema::table('oauth_auth_codes', function (Blueprint $table) {
                if (Schema::hasColumn('oauth_auth_codes', 'user_id')) {
                    $table->dropIndex(['user_id']);
                    $table->string('user_id')->nullable()->change();
                    $table->index('user_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert oauth_access_tokens table
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->string('user_id')->nullable()->change();
            $table->index('user_id');
        });

        // Revert oauth_refresh_tokens table
        if (Schema::hasTable('oauth_refresh_tokens')) {
            Schema::table('oauth_refresh_tokens', function (Blueprint $table) {
                if (Schema::hasColumn('oauth_refresh_tokens', 'user_id')) {
                    $table->string('user_id')->nullable()->change();
                }
            });
        }

        // Revert oauth_auth_codes table
        if (Schema::hasTable('oauth_auth_codes')) {
            Schema::table('oauth_auth_codes', function (Blueprint $table) {
                if (Schema::hasColumn('oauth_auth_codes', 'user_id')) {
                    $table->dropIndex(['user_id']);
                    $table->string('user_id')->nullable()->change();
                    $table->index('user_id');
                }
            });
        }
    }
};
