<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->string('resolution_code', 40)->nullable()->after('processed_at');
            $table->text('resolution_note')->nullable()->after('resolution_code');
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropColumn(['resolution_code', 'resolution_note']);
        });
    }
};
