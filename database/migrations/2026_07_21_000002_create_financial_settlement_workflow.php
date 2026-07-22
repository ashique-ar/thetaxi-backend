<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('payment_arrangement_status')->default('collection_due')->index();
            $table->string('customer_settlement_status')->default('not_applicable')->index();
            $table->string('corporate_settlement_status')->default('not_applicable')->index();
            $table->string('driver_collection_status')->default('not_required')->index();
            $table->string('invoice_status')->default('not_required')->index();
            $table->string('refund_status')->default('none')->index();
            $table->date('settlement_due_date')->nullable()->index();
            $table->timestamp('settled_at')->nullable();
        });
        Schema::table('service_types',function(Blueprint $table){$table->string('default_payment_arrangement')->nullable();$table->string('deposit_mode')->default('none');$table->decimal('deposit_value',12,2)->default(0);$table->unsignedInteger('settlement_due_days')->nullable();});

        Schema::table('booking_payment_receipts', function (Blueprint $table) {
            $table->string('received_via')->default('company')->index();
            $table->string('payer_type')->nullable()->index();
            $table->uuid('payer_id')->nullable()->index();
            $table->uuid('driver_id')->nullable()->index();
            $table->decimal('allocated_amount', 12, 2)->default(0);
            $table->decimal('driver_company_settled_amount', 12, 2)->default(0);
            $table->string('allocation_status')->default('unallocated')->index();
            $table->string('driver_company_settlement_status')->default('not_applicable')->index();
            $table->timestamp('driver_company_settled_at')->nullable();
        });

        Schema::create('financial_account_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('settlement_number')->unique();
            $table->string('owner_type')->index();
            $table->uuid('owner_id')->index();
            $table->string('billing_cycle')->default('ad_hoc');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->string('invoice_number')->nullable()->index();
            $table->decimal('charges_total', 12, 2)->default(0);
            $table->decimal('payments_total', 12, 2)->default(0);
            $table->decimal('refunds_total', 12, 2)->default(0);
            $table->decimal('adjustments_total', 12, 2)->default(0);
            $table->decimal('outstanding_total', 12, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->text('dispute_reason')->nullable(); $table->timestamp('disputed_at')->nullable(); $table->uuid('disputed_by')->nullable()->index();
            $table->timestamp('resolved_at')->nullable(); $table->uuid('resolved_by')->nullable()->index(); $table->text('resolution_notes')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_type', 'owner_id', 'status'], 'financial_settlement_owner_status_idx');
        });

        Schema::create('financial_settlement_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('settlement_id')->index();
            $table->uuid('booking_id')->index();
            $table->decimal('charge_amount', 12, 2);
            $table->decimal('paid_before_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('adjustment_amount', 12, 2)->default(0);
            $table->decimal('allocated_amount', 12, 2)->default(0);
            $table->decimal('outstanding_amount', 12, 2);
            $table->string('status')->default('open')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['settlement_id', 'booking_id'], 'financial_settlement_booking_unique');
        });

        Schema::create('financial_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('settlement_id')->index();
            $table->uuid('settlement_item_id')->index();
            $table->uuid('booking_id')->index();
            $table->uuid('payment_receipt_id')->index();
            $table->decimal('amount', 12, 2);
            $table->uuid('allocated_by')->nullable()->index();
            $table->timestamp('allocated_at');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('financial_adjustments', function(Blueprint $table){
            $table->uuid('id')->primary();$table->uuid('settlement_id')->index();$table->uuid('settlement_item_id')->index();$table->uuid('booking_id')->index();
            $table->string('type')->index();$table->decimal('amount',12,2);$table->text('reason');$table->string('reference')->nullable();
            $table->uuid('created_user_id')->nullable()->index();$table->timestamps();$table->softDeletes();
        });
        Schema::create('financial_settlement_documents',function(Blueprint $table){
            $table->uuid('id')->primary();$table->uuid('settlement_id')->unique();$table->string('invoice_number')->unique();
            $table->string('status')->default('issued')->index();$table->string('pdf_path')->nullable();$table->string('pdf_disk')->default('local');
            $table->timestamp('generated_at')->nullable();$table->timestamp('sent_at')->nullable();$table->string('sent_to')->nullable();$table->text('last_error')->nullable();
            $table->timestamps();$table->softDeletes();
        });
        Schema::create('financial_audit_events',function(Blueprint $table){
            $table->uuid('id')->primary();$table->string('subject_type')->index();$table->uuid('subject_id')->index();$table->uuid('booking_id')->nullable()->index();
            $table->string('event_type')->index();$table->string('from_status')->nullable();$table->string('to_status')->nullable();$table->decimal('amount',12,2)->nullable();
            $table->json('metadata')->nullable();$table->uuid('performed_by')->nullable()->index();$table->timestamp('occurred_at')->index();$table->timestamps();
        });

        Schema::create('driver_cash_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->string('settlement_number')->unique(); $table->uuid('driver_id')->index();
            $table->decimal('amount',12,2); $table->string('status')->default('settled')->index(); $table->date('due_date')->nullable()->index();
            $table->timestamp('handed_over_at'); $table->string('reference')->nullable(); $table->json('proof_files')->nullable();
            $table->text('notes')->nullable(); $table->uuid('received_by')->nullable()->index(); $table->uuid('created_user_id')->nullable()->index();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('driver_cash_settlement_items', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('driver_cash_settlement_id')->index(); $table->uuid('payment_receipt_id')->index();
            $table->uuid('booking_id')->index(); $table->decimal('amount',12,2); $table->timestamps(); $table->softDeletes();
        });
        Schema::table('driver_hire_settlements', function (Blueprint $table) {
            $table->decimal('cash_collected_unsettled',12,2)->default(0);
            $table->date('settlement_due_date')->nullable()->index();$table->string('payment_reference')->nullable();$table->json('settlement_proof_files')->nullable();
            $table->timestamp('settlement_recorded_at')->nullable();$table->uuid('settlement_recorded_by')->nullable()->index();
            $table->text('dispute_reason')->nullable();$table->timestamp('disputed_at')->nullable();$table->uuid('disputed_by')->nullable()->index();
        });

        DB::table('bookings')->where('payment_collection_method', 'monthly_invoice')->update([
            'payment_status' => 'corporate_account',
            'payment_arrangement_status' => 'corporate_credit',
            'corporate_settlement_status' => 'open',
            'customer_settlement_status' => 'not_applicable',
            'driver_collection_status' => 'not_required',
            'invoice_status' => 'pending_issue',
        ]);
        DB::table('bookings')->where('payment_collection_method', 'account_credit')->update([
            'payment_status' => 'credit_terms',
            'payment_arrangement_status' => 'customer_credit',
            'customer_settlement_status' => 'open',
            'corporate_settlement_status' => 'not_applicable',
            'driver_collection_status' => 'not_required',
        ]);
        DB::table('bookings')->whereIn('payment_collection_method', ['cash_to_driver','pay_at_end'])->whereNotIn('payment_status', ['paid','refunded'])->update([
            'payment_status' => 'collection_due', 'payment_arrangement_status' => 'driver_collection', 'driver_collection_status' => 'collection_due',
        ]);
        DB::table('bookings')->whereIn('payment_collection_method', ['advance_then_balance','deposit_then_balance'])->whereNotIn('payment_status', ['paid','refunded'])->update([
            'payment_status' => 'advance_due', 'payment_arrangement_status' => 'advance_then_balance', 'driver_collection_status' => 'collection_due',
        ]);
        DB::table('bookings')->whereIn('payment_collection_method',['complimentary','waived'])->update(['payment_status'=>'waived','payment_arrangement_status'=>'complimentary','payment_responsibility'=>'company','driver_collection_status'=>'not_required','customer_settlement_status'=>'not_applicable','corporate_settlement_status'=>'not_applicable']);
    }

    public function down(): void
    {
        Schema::table('driver_hire_settlements', fn(Blueprint $table)=>$table->dropColumn(['cash_collected_unsettled','settlement_due_date','payment_reference','settlement_proof_files','settlement_recorded_at','settlement_recorded_by','dispute_reason','disputed_at','disputed_by']));
        Schema::dropIfExists('driver_cash_settlement_items');
        Schema::dropIfExists('driver_cash_settlements');
        Schema::dropIfExists('financial_adjustments');
        Schema::dropIfExists('financial_audit_events');
        Schema::dropIfExists('financial_settlement_documents');
        Schema::dropIfExists('financial_payment_allocations');
        Schema::dropIfExists('financial_settlement_items');
        Schema::dropIfExists('financial_account_settlements');
        Schema::table('booking_payment_receipts', fn (Blueprint $table) => $table->dropColumn([
            'received_via', 'payer_type', 'payer_id', 'driver_id', 'allocated_amount', 'driver_company_settled_amount', 'allocation_status',
            'driver_company_settlement_status', 'driver_company_settled_at',
        ]));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn([
            'payment_arrangement_status', 'customer_settlement_status', 'corporate_settlement_status',
            'driver_collection_status', 'invoice_status', 'refund_status', 'settlement_due_date', 'settled_at',
        ]));
        Schema::table('service_types',fn(Blueprint $table)=>$table->dropColumn(['default_payment_arrangement','deposit_mode','deposit_value','settlement_due_days']));
    }
};
