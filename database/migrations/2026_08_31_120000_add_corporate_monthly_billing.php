<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corporate_billing_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id')->index();
            $table->string('billing_cycle')->default('monthly');
            $table->unsignedTinyInteger('cutoff_day')->default(31);
            $table->unsignedTinyInteger('invoice_day')->default(1);
            $table->unsignedSmallInteger('due_days')->default(30);
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->string('currency', 3)->default('LKR');
            $table->string('billing_name')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->text('billing_address')->nullable();
            $table->json('recipients')->nullable();
            $table->json('delivery_preferences')->nullable();
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['corporate_id', 'effective_from', 'effective_to'], 'corporate_billing_terms_effective_idx');
        });

        Schema::table('financial_account_settlements', function (Blueprint $table) {
            $table->uuid('billing_terms_id')->nullable()->index()->after('owner_id');
            $table->string('generation_key')->nullable()->unique()->after('billing_cycle');
            $table->json('billing_terms_snapshot')->nullable()->after('generation_key');
            $table->json('statement_snapshot')->nullable()->after('billing_terms_snapshot');
            $table->string('statement_pdf_path')->nullable()->after('statement_snapshot');
            $table->string('statement_pdf_disk')->default('local')->after('statement_pdf_path');
            $table->timestamp('statement_sent_at')->nullable()->after('statement_pdf_disk');
            $table->json('statement_sent_to')->nullable()->after('statement_sent_at');
            $table->text('statement_last_error')->nullable()->after('statement_sent_to');
        });

        Schema::table('financial_settlement_items', function (Blueprint $table) {
            $table->json('booking_item_ids')->nullable()->after('booking_id');
            $table->json('source_snapshot')->nullable()->after('booking_item_ids');
        });
    }

    public function down(): void
    {
        Schema::table('financial_settlement_items', function (Blueprint $table) {
            $table->dropColumn(['booking_item_ids', 'source_snapshot']);
        });
        Schema::table('financial_account_settlements', function (Blueprint $table) {
            $table->dropUnique(['generation_key']);
            $table->dropColumn(['billing_terms_id', 'generation_key', 'billing_terms_snapshot', 'statement_snapshot', 'statement_pdf_path', 'statement_pdf_disk', 'statement_sent_at', 'statement_sent_to', 'statement_last_error']);
        });
        Schema::dropIfExists('corporate_billing_terms');
    }
};
