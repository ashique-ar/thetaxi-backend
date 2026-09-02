<?php

use App\Models\Corporate\Corporate;
use App\Models\Booking\Booking;
use App\Models\Finance\FinancialAccountSettlement;
use App\Services\CorporateFinancialProjectionService;
use App\Services\CorporateMonthlyBillingService;
use App\Services\FinancialAccountSettlementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;

beforeEach(function () {
    activity()->disableLogging();
    foreach (['financial_audit_events','financial_settlement_documents','financial_settlement_items','financial_account_settlements','invoices','booking_payment_receipts','booking_items','bookings','service_types','corporate_billing_terms','corporates','business_settings'] as $table) Schema::dropIfExists($table);
    Schema::create('business_settings', function(Blueprint $t){$t->uuid('id')->primary();$t->string('type');$t->text('value')->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('corporates', function(Blueprint $t){$t->uuid('id')->primary();$t->string('name');$t->string('billing_address')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();$t->softDeletes();});
    Schema::create('corporate_billing_terms', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('corporate_id');$t->string('billing_cycle');$t->unsignedTinyInteger('cutoff_day');$t->unsignedTinyInteger('invoice_day');$t->unsignedSmallInteger('due_days');$t->decimal('credit_limit',14,2)->nullable();$t->string('currency',3);$t->string('billing_name')->nullable();$t->string('tax_identifier')->nullable();$t->text('billing_address')->nullable();$t->json('recipients')->nullable();$t->json('delivery_preferences')->nullable();$t->date('effective_from');$t->date('effective_to')->nullable();$t->boolean('is_active')->default(true);$t->uuid('created_user_id')->nullable();$t->uuid('updated_user_id')->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('service_types', function(Blueprint $t){$t->uuid('id')->primary();$t->string('name');$t->boolean('is_active')->default(true);$t->timestamps();$t->softDeletes();});
    Schema::create('bookings', function(Blueprint $t){$t->uuid('id')->primary();$t->string('booking_number')->nullable();$t->boolean('is_corporate_booking')->default(true);$t->uuid('corporate_account_id');$t->string('status')->nullable();$t->string('payment_collection_method')->default('monthly_invoice');$t->string('currency',3)->default('LKR');$t->decimal('total_estimated',12,2)->nullable();$t->decimal('total_actual',12,2)->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('booking_items', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('booking_id');$t->uuid('service_type_id')->nullable();$t->dateTime('from_date')->nullable();$t->dateTime('to_date')->nullable();$t->string('status')->nullable();$t->timestamp('final_priced_at')->nullable();$t->decimal('total_price',12,2)->default(0);$t->string('currency',3)->default('LKR');$t->json('pricing_breakdown')->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('booking_payment_receipts', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('booking_id');$t->decimal('amount',12,2);$t->decimal('refunded_amount',12,2)->default(0);$t->decimal('allocated_amount',12,2)->default(0);$t->string('payment_purpose')->default('booking_payment');$t->timestamps();$t->softDeletes();});
    Schema::create('invoices', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('booking_id');$t->string('invoice_number');$t->string('status');$t->decimal('total_amount',12,2);$t->date('due_date')->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('financial_account_settlements', function(Blueprint $t){$t->uuid('id')->primary();$t->string('settlement_number')->unique();$t->string('owner_type');$t->uuid('owner_id');$t->uuid('billing_terms_id')->nullable();$t->string('billing_cycle');$t->string('generation_key')->nullable()->unique();$t->json('billing_terms_snapshot')->nullable();$t->json('statement_snapshot')->nullable();$t->string('statement_pdf_path')->nullable();$t->string('statement_pdf_disk')->default('local');$t->timestamp('statement_sent_at')->nullable();$t->json('statement_sent_to')->nullable();$t->text('statement_last_error')->nullable();$t->date('period_start');$t->date('period_end');$t->date('due_date')->nullable();$t->string('status')->default('draft');$t->string('invoice_number')->nullable();$t->decimal('charges_total',12,2)->default(0);$t->decimal('payments_total',12,2)->default(0);$t->decimal('refunds_total',12,2)->default(0);$t->decimal('adjustments_total',12,2)->default(0);$t->decimal('outstanding_total',12,2)->default(0);$t->timestamp('issued_at')->nullable();$t->timestamp('settled_at')->nullable();$t->text('dispute_reason')->nullable();$t->uuid('created_user_id')->nullable();$t->uuid('updated_user_id')->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('financial_settlement_items', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('settlement_id');$t->uuid('booking_id');$t->json('booking_item_ids')->nullable();$t->json('source_snapshot')->nullable();$t->decimal('charge_amount',12,2);$t->decimal('paid_before_amount',12,2)->default(0);$t->decimal('refund_amount',12,2)->default(0);$t->decimal('adjustment_amount',12,2)->default(0);$t->decimal('allocated_amount',12,2)->default(0);$t->decimal('outstanding_amount',12,2);$t->string('status')->default('open');$t->timestamps();$t->softDeletes();});
    Schema::create('financial_settlement_documents', function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('settlement_id');$t->string('invoice_number');$t->string('status')->default('issued');$t->string('pdf_path')->nullable();$t->string('pdf_disk')->default('local');$t->timestamp('generated_at')->nullable();$t->timestamp('sent_at')->nullable();$t->string('sent_to')->nullable();$t->text('last_error')->nullable();$t->timestamps();$t->softDeletes();});
    Schema::create('financial_audit_events', function(Blueprint $t){$t->uuid('id')->primary();$t->string('subject_type');$t->uuid('subject_id');$t->uuid('booking_id')->nullable();$t->string('event_type');$t->string('from_status')->nullable();$t->string('to_status')->nullable();$t->decimal('amount',12,2)->nullable();$t->json('metadata')->nullable();$t->uuid('performed_by')->nullable();$t->timestamp('occurred_at');$t->timestamps();});
    Storage::fake('local');
});

function monthlyBillingService(): CorporateMonthlyBillingService {
    $settlements = Mockery::mock(FinancialAccountSettlementService::class);
    $settlements->shouldReceive('recalculate')->andReturnUsing(function(FinancialAccountSettlement $settlement){$items=$settlement->items()->get();$out=(float)$items->sum('outstanding_amount');$paid=(float)$items->sum(fn($i)=>(float)$i->paid_before_amount+(float)$i->allocated_amount);$settlement->update(['charges_total'=>$items->sum('charge_amount'),'payments_total'=>$paid,'refunds_total'=>$items->sum('refund_amount'),'adjustments_total'=>$items->sum('adjustment_amount'),'outstanding_total'=>$out,'status'=>$out<=0?'paid':($paid>0?'partial':'draft')]);return $settlement->fresh();});
    return new CorporateMonthlyBillingService($settlements);
}

it('versions terms previews finalized trips and generates one immutable monthly statement', function () {
    $corporateId='10000000-0000-4000-8000-000000000001';$otherId='10000000-0000-4000-8000-000000000002';
    DB::table('corporates')->insert([['id'=>$corporateId,'name'=>'Acme','is_active'=>true],['id'=>$otherId,'name'=>'Other','is_active'=>true]]);
    DB::table('service_types')->insert(['id'=>'20000000-0000-4000-8000-000000000001','name'=>'Transfer','is_active'=>true]);
    $service=monthlyBillingService();$corporate=Corporate::findOrFail($corporateId);
    $service->storeTerms($corporate,['billing_cycle'=>'monthly','cutoff_day'=>31,'invoice_day'=>1,'due_days'=>30,'credit_limit'=>10000,'currency'=>'LKR','effective_from'=>'2026-08-01','recipients'=>['finance@acme.test'],'delivery_preferences'=>['email_invoice'=>true,'email_statement'=>true]],null);
    expect(fn()=>$service->storeTerms($corporate,['billing_cycle'=>'monthly','cutoff_day'=>31,'invoice_day'=>1,'due_days'=>30,'currency'=>'LKR','effective_from'=>'2026-08-15'],null))->toThrow(ValidationException::class);
    DB::table('bookings')->insert([['id'=>'30000000-0000-4000-8000-000000000001','booking_number'=>'A-1','corporate_account_id'=>$corporateId,'status'=>'completed','payment_collection_method'=>'monthly_invoice','currency'=>'LKR','total_actual'=>150],['id'=>'30000000-0000-4000-8000-000000000002','booking_number'=>'O-1','corporate_account_id'=>$otherId,'status'=>'completed','payment_collection_method'=>'monthly_invoice','currency'=>'LKR','total_actual'=>900],['id'=>'30000000-0000-4000-8000-000000000003','booking_number'=>'CASH-1','corporate_account_id'=>$corporateId,'status'=>'completed','payment_collection_method'=>'cash_to_driver','currency'=>'LKR','total_actual'=>75]]);
    DB::table('booking_items')->insert([['id'=>'40000000-0000-4000-8000-000000000001','booking_id'=>'30000000-0000-4000-8000-000000000001','service_type_id'=>'20000000-0000-4000-8000-000000000001','from_date'=>'2026-08-10','to_date'=>'2026-08-10','status'=>'completed','final_priced_at'=>'2026-08-10 12:00:00','total_price'=>150,'currency'=>'LKR','pricing_breakdown'=>'{"final_pricing":{"total_amount":150}}'],['id'=>'40000000-0000-4000-8000-000000000002','booking_id'=>'30000000-0000-4000-8000-000000000001','service_type_id'=>'20000000-0000-4000-8000-000000000001','from_date'=>'2026-08-12','to_date'=>'2026-08-12','status'=>'pending','final_priced_at'=>null,'total_price'=>50,'currency'=>'LKR','pricing_breakdown'=>null],['id'=>'40000000-0000-4000-8000-000000000003','booking_id'=>'30000000-0000-4000-8000-000000000002','service_type_id'=>'20000000-0000-4000-8000-000000000001','from_date'=>'2026-08-11','to_date'=>'2026-08-11','status'=>'completed','final_priced_at'=>'2026-08-11 12:00:00','total_price'=>900,'currency'=>'LKR','pricing_breakdown'=>null],['id'=>'40000000-0000-4000-8000-000000000004','booking_id'=>'30000000-0000-4000-8000-000000000003','service_type_id'=>'20000000-0000-4000-8000-000000000001','from_date'=>'2026-08-13','to_date'=>'2026-08-13','status'=>'completed','final_priced_at'=>'2026-08-13 12:00:00','total_price'=>75,'currency'=>'LKR','pricing_breakdown'=>null]]);
    DB::table('booking_payment_receipts')->insert(['id'=>'50000000-0000-4000-8000-000000000001','booking_id'=>'30000000-0000-4000-8000-000000000001','amount'=>50,'refunded_amount'=>0,'allocated_amount'=>0,'payment_purpose'=>'booking_payment']);
    $preview=$service->preview($corporateId,'2026-08-01','2026-08-31');
    expect($preview['booking_count'])->toBe(1)->and($preview['trip_count'])->toBe(1)->and($preview['charges_total'])->toBe(150.0)->and($preview['excluded'])->toHaveCount(2)->and($preview['excluded']->pluck('reason')->all())->toContain('not_completed', 'not_monthly_corporate_credit');
    $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $pdf->shouldReceive('output')->once()->andReturn('statement-pdf');
    Pdf::shouldReceive('loadView')->once()->andReturn($pdf);
    $first=$service->generate($corporateId,'2026-08-01','2026-08-31',null);$second=$service->generate($corporateId,'2026-08-01','2026-08-31',null);
    expect($second->id)->toBe($first->id)->and(FinancialAccountSettlement::count())->toBe(1)->and((float)$first->charges_total)->toBe(150.0)->and((float)$first->payments_total)->toBe(50.0)->and((float)$first->outstanding_total)->toBe(100.0)->and($first->items()->first()->booking_item_ids)->toBe(['40000000-0000-4000-8000-000000000001'])->and((float)$first->statement_snapshot['account_closing_balance'])->toBe(100.0);
    Storage::disk('local')->assertExists($first->statement_pdf_path);
    Mail::fake();
    $issuer=Mockery::mock(FinancialAccountSettlementService::class);
    $issuer->shouldReceive('issue')->once()->andReturnUsing(function(FinancialAccountSettlement $row){$row->update(['status'=>'open','invoice_number'=>'INV-AUG','issued_at'=>now()]);return $row->fresh();});
    $issued=(new CorporateMonthlyBillingService($issuer))->issue($first,true);
    expect($issued->status)->toBe('open')->and($issued->statement_sent_at)->not->toBeNull()->and($issued->statement_sent_to)->toBe(['finance@acme.test']);
    $first=$issued;
    $account=(new CorporateFinancialProjectionService())->accountSummary($corporateId);
    expect($account['summary']['charges_total'])->toBe(150.0)->and($account['summary']['payments_total'])->toBe(50.0)->and($account['summary']['outstanding_total'])->toBe(100.0)->and($account['aging']['current'])->toBe(100.0)->and($account['settlements'])->toHaveCount(1);
    DB::table('invoices')->insert(['id'=>'70000000-0000-4000-8000-000000000001','booking_id'=>'30000000-0000-4000-8000-000000000001','invoice_number'=>'DIRECT-DUPLICATE','status'=>'issued','total_amount'=>999]);
    $metrics=(new CorporateFinancialProjectionService())->summarizeBookings(Booking::whereKey('30000000-0000-4000-8000-000000000001')->get());
    expect($metrics['invoiced_value'])->toBe(150.0)->and($metrics['paid_value'])->toBe(50.0)->and($metrics['outstanding_value'])->toBe(100.0)->and($metrics['invoiced_booking_count'])->toBe(1);
});
