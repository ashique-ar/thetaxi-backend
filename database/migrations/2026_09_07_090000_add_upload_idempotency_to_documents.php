<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('upload_idempotency_key')->nullable()->after('document_number');
            $table->char('upload_request_checksum', 64)->nullable()->after('upload_idempotency_key');
            $table->unique('upload_idempotency_key', 'documents_upload_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_upload_idempotency_unique');
            $table->dropColumn(['upload_idempotency_key', 'upload_request_checksum']);
        });
    }
};
