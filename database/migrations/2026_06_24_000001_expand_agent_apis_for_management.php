<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_apis', function (Blueprint $table) {
            $table->uuid('agent_id')->nullable()->after('id')->index();
            $table->unsignedInteger('rate_limit')->default(1000)->after('api_key');
            $table->string('access_level', 30)->default('read')->after('rate_limit');
            $table->text('allowed_ips')->nullable()->after('access_level');
            $table->string('status', 20)->default('active')->after('allowed_ips')->index();
            $table->unsignedBigInteger('total_requests')->default(0)->after('status');
            $table->timestamp('last_used_at')->nullable()->after('total_requests');
        });
    }

    public function down(): void
    {
        Schema::table('agent_apis', function (Blueprint $table) {
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'agent_id',
                'rate_limit',
                'access_level',
                'allowed_ips',
                'status',
                'total_requests',
                'last_used_at',
            ]);
        });
    }
};
