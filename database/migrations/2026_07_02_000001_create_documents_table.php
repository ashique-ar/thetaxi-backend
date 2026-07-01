<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('documentable');
            $table->string('document_type', 50);
            $table->string('document_number');
            $table->date('expiry_date')->nullable();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('verification_notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['document_type', 'status']);
            $table->index('document_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
