<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->foreignUuid('linked_inquiry_id')->nullable()->after('connection_id')->constrained('inquiries')->restrictOnDelete();
            $table->foreignUuid('processed_by_user_id')->nullable()->after('status')->constrained('users')->restrictOnDelete();
            $table->timestampTz('processed_at')->nullable()->after('processed_by_user_id');
            $table->index(['company_id', 'status', 'processed_at'], 'integration_event_processing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('integration_event_processing_idx');
            $table->dropConstrainedForeignId('processed_by_user_id');
            $table->dropConstrainedForeignId('linked_inquiry_id');
            $table->dropColumn('processed_at');
        });
    }
};
