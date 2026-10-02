<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_people_identity_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('duplicate_review_id')->constrained('hr_people_duplicate_reviews')->restrictOnDelete();
            $table->foreignUuid('alias_staff_id')->unique()->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('canonical_staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('status', 30)->default('active');
            $table->text('reason');
            $table->char('evidence_checksum', 64);
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->index(['company_id', 'canonical_staff_id'], 'hr_people_identity_canonical_idx');
        });

        Schema::table('hr_people_duplicate_reviews', function (Blueprint $table) {
            $table->string('consolidation_status', 30)->nullable();
            $table->json('consolidation_snapshot')->nullable();
            $table->foreignUuid('consolidated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('consolidated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_people_identity_links') && DB::table('hr_people_identity_links')->exists()) {
            throw new RuntimeException('Rollback refused: revoke and export canonical identity links first.');
        }
        Schema::table('hr_people_duplicate_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consolidated_by');
            $table->dropColumn(['consolidation_status', 'consolidation_snapshot', 'consolidated_at']);
        });
        Schema::dropIfExists('hr_people_identity_links');
    }
};
