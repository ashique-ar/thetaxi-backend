<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('provider_status')->nullable()->index()->after('status');
            $table->timestamp('provider_status_at')->nullable()->index()->after('provider_status');
            $table->unsignedSmallInteger('segments')->default(1)->after('message');
            $table->decimal('unit_cost', 12, 4)->nullable()->after('segments');
            $table->decimal('total_cost', 12, 4)->nullable()->after('unit_cost');
            $table->string('cost_currency', 3)->nullable()->after('total_cost');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['provider_status']);
            $table->dropIndex(['provider_status_at']);
            $table->dropColumn(['provider_status', 'provider_status_at', 'segments', 'unit_cost', 'total_cost', 'cost_currency']);
        });
    }
};
