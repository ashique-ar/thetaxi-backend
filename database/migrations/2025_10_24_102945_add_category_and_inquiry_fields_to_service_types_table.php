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
        Schema::table('service_types', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type')->comment('Service category: airport, corporate, transport, etc.');
            $table->boolean('is_inquiry')->default(false)->after('is_internal')->comment('Whether this service type requires inquiry form instead of direct booking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_types', function (Blueprint $table) {
            $table->dropColumn(['category', 'is_inquiry']);
        });
    }
};
