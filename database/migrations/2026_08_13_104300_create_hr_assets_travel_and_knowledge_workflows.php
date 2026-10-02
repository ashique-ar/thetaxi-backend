<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_asset_types', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code',80); $t->string('name'); $t->boolean('returnable')->default(true); $t->boolean('consumable')->default(false);
            $t->boolean('requires_employee_ack')->default(true); $t->json('required_condition_fields')->nullable(); $t->string('status',30)->default('active'); $t->timestamps();
            $t->unique(['company_id','code']);
        });
        Schema::create('hr_asset_items', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('asset_type_id')->constrained('hr_asset_types')->restrictOnDelete();
            $t->string('asset_tag',120); $t->string('serial_or_imei',180)->nullable(); $t->string('make')->nullable(); $t->string('model')->nullable();
            $t->date('purchased_at')->nullable(); $t->date('warranty_until')->nullable(); $t->decimal('reference_value',20,4)->nullable(); $t->string('currency',3)->nullable();
            $t->string('location_code',80)->nullable(); $t->string('status',30)->default('available'); $t->string('condition_code',40)->default('serviceable');
            $t->string('accounting_reference',160)->nullable(); $t->string('procurement_reference',160)->nullable(); $t->json('accessories')->nullable(); $t->json('ownership_snapshot')->nullable(); $t->timestamps();
            $t->unique(['company_id','asset_tag']); $t->index(['company_id','status','asset_type_id']);
        });
        Schema::create('hr_asset_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->foreignUuid('asset_type_id')->constrained('hr_asset_types')->restrictOnDelete(); $t->foreignUuid('requested_asset_item_id')->nullable()->constrained('hr_asset_items')->restrictOnDelete();
            $t->foreignUuid('replaces_custody_assignment_id')->nullable()->constrained('hr_custody_assignments')->restrictOnDelete(); $t->string('request_kind',30); $t->string('reason_code',60); $t->text('reason');
            $t->date('required_from'); $t->date('expected_return_at')->nullable(); $t->json('cost_allocation_snapshot')->nullable(); $t->string('status',30)->default('pending_approval');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete(); $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('approved_at')->nullable();
            $t->foreignUuid('allocated_asset_item_id')->nullable()->constrained('hr_asset_items')->restrictOnDelete(); $t->foreignUuid('result_custody_assignment_id')->nullable()->constrained('hr_custody_assignments')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('hr_asset_request_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('asset_request_id')->constrained('hr_asset_requests')->restrictOnDelete(); $t->string('event_type',60); $t->string('from_status',30)->nullable(); $t->string('to_status',30)->nullable();
            $t->json('evidence')->nullable(); $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->index(['asset_request_id','occurred_at']);
        });
        Schema::table('hr_custody_assignments', function (Blueprint $t) {
            $t->dropUnique(['company_id','custody_type','item_code']);
            $t->foreignUuid('asset_item_id')->nullable()->after('staff_id')->constrained('hr_asset_items')->restrictOnDelete();
            $t->foreignUuid('asset_request_id')->nullable()->after('asset_item_id')->constrained('hr_asset_requests')->restrictOnDelete();
            $t->string('issue_reason',60)->nullable(); $t->timestamp('acknowledged_at')->nullable(); $t->foreignUuid('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('return_disposition',40)->nullable(); $t->text('return_reason')->nullable(); $t->json('return_condition_snapshot')->nullable();
            $t->index(['company_id','custody_type','item_code']);
        });
        Schema::create('hr_custody_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('custody_assignment_id')->constrained('hr_custody_assignments')->restrictOnDelete(); $t->string('event_type',60); $t->string('from_status',30)->nullable(); $t->string('to_status',30)->nullable();
            $t->json('condition_snapshot')->nullable(); $t->json('accessory_snapshot')->nullable(); $t->text('reason')->nullable(); $t->json('evidence')->nullable(); $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->index(['custody_assignment_id','occurred_at']);
        });
        Schema::create('hr_phone_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('asset_item_id')->nullable()->constrained('hr_asset_items')->restrictOnDelete();
            $t->string('subscription_code',120); $t->string('carrier'); $t->string('masked_msisdn',40); $t->text('encrypted_identifiers'); $t->string('plan_name')->nullable(); $t->string('billing_account_reference',160)->nullable();
            $t->string('cost_centre_code',80)->nullable(); $t->decimal('allowance_amount',20,4)->nullable(); $t->string('currency',3)->nullable(); $t->boolean('roaming_allowed')->default(false); $t->boolean('data_allowed')->default(true);
            $t->string('status',30)->default('available'); $t->date('activated_at')->nullable(); $t->date('deactivated_at')->nullable(); $t->timestamps(); $t->unique(['company_id','subscription_code']);
        });
        Schema::create('hr_phone_usage_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('phone_subscription_id')->constrained('hr_phone_subscriptions')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $t->date('period_start'); $t->date('period_end'); $t->decimal('company_amount',20,4); $t->decimal('employee_excess_amount',20,4)->default(0); $t->string('currency',3); $t->json('policy_snapshot'); $t->string('status',30)->default('pending_review');
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('reviewed_at')->nullable(); $t->text('dispute_reason')->nullable(); $t->string('recovery_outbox_id',36)->nullable(); $t->char('source_checksum',64); $t->timestamps(); $t->unique(['phone_subscription_id','period_start','period_end']);
        });
        Schema::create('hr_travel_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete(); $t->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete(); $t->string('request_number',80)->unique();
            $t->text('purpose'); $t->string('origin',255); $t->string('destination',255); $t->timestampTz('departs_at'); $t->timestampTz('returns_at'); $t->string('timezone',80); $t->json('itinerary');
            $t->string('currency',3); $t->decimal('estimated_cost',20,4); $t->decimal('requested_advance',20,4)->default(0); $t->json('per_diem_snapshot')->nullable(); $t->json('allocation_snapshot');
            $t->string('status',30)->default('pending_approval'); $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete(); $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('approved_at')->nullable();
            $t->string('booking_reference',160)->nullable(); $t->foreignUuid('settlement_claim_id')->nullable()->constrained('hr_expense_claims')->restrictOnDelete(); $t->decimal('unused_advance_amount',20,4)->nullable(); $t->timestamps();
        });
        Schema::create('hr_travel_request_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('travel_request_id')->constrained('hr_travel_requests')->restrictOnDelete(); $t->string('event_type',60); $t->string('from_status',30)->nullable(); $t->string('to_status',30)->nullable(); $t->json('evidence')->nullable();
            $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->index(['travel_request_id','occurred_at']);
        });
        Schema::table('hr_knowledge_articles', function (Blueprint $t) {
            $t->foreignUuid('created_by')->nullable()->after('acknowledgement_required')->constrained('users')->restrictOnDelete(); $t->char('content_checksum',64)->nullable();
        });
        Schema::create('hr_knowledge_article_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('article_id')->constrained('hr_knowledge_articles')->restrictOnDelete(); $t->string('event_type',60); $t->string('from_status',30)->nullable(); $t->string('to_status',30)->nullable(); $t->text('reason')->nullable();
            $t->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete(); $t->timestamp('occurred_at'); $t->index(['article_id','occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_knowledge_article_events');
        Schema::table('hr_knowledge_articles',fn(Blueprint$t)=>$t->dropConstrainedForeignId('created_by'));
        Schema::table('hr_knowledge_articles',fn(Blueprint$t)=>$t->dropColumn('content_checksum'));
        foreach(['hr_travel_request_events','hr_travel_requests','hr_phone_usage_allocations','hr_phone_subscriptions','hr_custody_events'] as $table) Schema::dropIfExists($table);
        Schema::table('hr_custody_assignments',function(Blueprint$t){$t->dropConstrainedForeignId('asset_item_id');$t->dropConstrainedForeignId('asset_request_id');$t->dropConstrainedForeignId('acknowledged_by');$t->dropColumn(['issue_reason','acknowledged_at','return_disposition','return_reason','return_condition_snapshot']);$t->dropIndex(['company_id','custody_type','item_code']);$t->unique(['company_id','custody_type','item_code']);});
        foreach(['hr_asset_request_events','hr_asset_requests','hr_asset_items','hr_asset_types'] as $table) Schema::dropIfExists($table);
    }
};
