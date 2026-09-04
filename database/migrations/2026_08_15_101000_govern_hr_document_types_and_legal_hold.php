<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §5.3: "Document types, required-document rules by role/employment type" and
 * "retention/legal hold rules may restrict deletion but must not make
 * required history silently disappear." Extends the existing generic
 * `documents` table (already hardened under Q0-03 with classification/
 * version/supersedes_id/retention_until) additively rather than creating a
 * parallel document store, and adds a governed reference register for
 * approved document types reusing the existing organization change-event
 * ledger (fourth aggregate type alongside organization_unit/
 * custom_field_definition/payroll_group).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUuid('employment_spell_id')->nullable()->after('documentable_id')
                ->constrained('hr_employment_spells')->nullOnDelete();
            $table->boolean('legal_hold')->default(false)->after('retention_until');
        });

        Schema::create('hr_document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 255);
            $table->string('category', 40);
            $table->json('required_for_staff_types')->nullable();
            $table->json('required_for_employment_types')->nullable();
            $table->boolean('requires_expiry')->default(false);
            $table->unsignedSmallInteger('renewal_reminder_days')->nullable();
            $table->string('status', 30)->default('active');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'category', 'status']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_organization_change_events')
            && DB::table('hr_organization_change_events')->where('aggregate_type', 'document_type')->exists()) {
            throw new \LogicException('Refusing to remove retained HR document-type governance history. Disable the feature without rolling back used schema.');
        }
        if (DB::table('documents')->where('legal_hold', true)->exists()) {
            throw new \LogicException('Refusing to drop legal_hold while a document remains under legal hold.');
        }

        Schema::dropIfExists('hr_document_types');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('legal_hold');
            $table->dropConstrainedForeignId('employment_spell_id');
        });
    }
};
