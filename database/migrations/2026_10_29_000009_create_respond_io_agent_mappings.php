<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respond_io_agent_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('external_agent_id', 120);
            $table->string('external_agent_name', 255)->nullable();
            $table->string('external_agent_email', 255)->nullable();
            $table->foreignUuid('erp_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('mapped_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['connection_id', 'external_agent_id'], 'respond_io_agent_mapping_provider_user_unique');
            $table->index(['company_id', 'erp_user_id'], 'respond_io_agent_mapping_erp_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respond_io_agent_mappings');
    }
};
