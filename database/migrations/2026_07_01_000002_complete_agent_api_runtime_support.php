<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'agent_id')) {
                $table->uuid('agent_id')->nullable()->index()->after('customer_id');
            }
        });

        Schema::table('agent_api_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_api_sessions', 'method')) {
                $table->string('method', 10)->nullable()->after('last_access');
                $table->string('path')->nullable()->after('method');
                $table->unsignedSmallInteger('status_code')->nullable()->after('path');
                $table->unsignedInteger('duration_ms')->nullable()->after('status_code');
                $table->string('ip_address', 45)->nullable()->after('duration_ms');
                $table->text('user_agent')->nullable()->after('ip_address');
                $table->json('metadata')->nullable()->after('user_agent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_api_sessions', function (Blueprint $table) {
            foreach (['method', 'path', 'status_code', 'duration_ms', 'ip_address', 'user_agent', 'metadata'] as $column) {
                if (Schema::hasColumn('agent_api_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'agent_id')) {
                $table->dropIndex(['agent_id']);
                $table->dropColumn('agent_id');
            }
        });
    }
};
