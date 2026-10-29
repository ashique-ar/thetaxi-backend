<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->unsignedSmallInteger('missed_call_callback_minutes')->nullable();
            $table->unsignedSmallInteger('missed_call_escalation_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn(['missed_call_callback_minutes', 'missed_call_escalation_minutes']);
        });
    }
};
