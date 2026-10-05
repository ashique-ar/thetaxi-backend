<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'created_at'], 'sms_messages_company_created_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('sms_messages')->whereNotNull('company_id')->exists()) {
            throw new RuntimeException('Cannot remove company ownership from retained SMS messages.');
        }

        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex('sms_messages_company_created_idx');
            $table->dropColumn('company_id');
        });
    }
};
