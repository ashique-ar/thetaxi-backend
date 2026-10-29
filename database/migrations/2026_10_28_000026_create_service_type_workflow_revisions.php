<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_type_workflow_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->unsignedInteger('revision');
            $table->string('change_type', 24);
            $table->string('status', 16)->default('active');
            $table->json('workflow_config')->nullable();
            $table->json('effective_settings');
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['service_type_id', 'revision'], 'service_type_workflow_revision_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('service_type_workflow_revisions') && DB::table('service_type_workflow_revisions')->exists()) {
            throw new RuntimeException('Workflow revision history exists; archive it before dropping this table.');
        }
        Schema::dropIfExists('service_type_workflow_revisions');
    }
};
