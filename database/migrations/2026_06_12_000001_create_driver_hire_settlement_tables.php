<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_batta_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_group_id')->index();
            $table->string('batta_category')->index();
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('night_amount', 12, 2)->default(0);
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['vehicle_group_id', 'batta_category', 'effective_from'], 'driver_batta_rules_unique_period');
        });

        Schema::create('driver_hire_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->index();
            $table->uuid('booking_item_id')->nullable()->index();
            $table->uuid('driver_id')->index();
            $table->uuid('vehicle_id')->nullable()->index();
            $table->uuid('vehicle_group_id')->index();
            $table->uuid('driver_log_id')->nullable()->index();
            $table->uuid('batta_rule_id')->nullable()->index();
            $table->string('batta_category')->nullable()->index();
            $table->decimal('base_batta_amount', 12, 2)->default(0);
            $table->unsignedInteger('night_count')->default(0);
            $table->decimal('night_batta_rate', 12, 2)->default(0);
            $table->decimal('night_batta_amount', 12, 2)->default(0);
            $table->decimal('manual_adjustment_amount', 12, 2)->default(0);
            $table->text('manual_adjustment_reason')->nullable();
            $table->decimal('approved_batta_amount', 12, 2)->default(0);
            $table->decimal('approved_expenses_total', 12, 2)->default(0);
            $table->decimal('iou_total', 12, 2)->default(0);
            $table->decimal('final_balance', 12, 2)->default(0);
            $table->string('status')->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('ops_reviewed_at')->nullable();
            $table->uuid('ops_reviewed_by')->nullable()->index();
            $table->text('ops_review_notes')->nullable();
            $table->timestamp('accounts_finalized_at')->nullable();
            $table->uuid('accounts_finalized_by')->nullable()->index();
            $table->text('accounts_notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['booking_id', 'driver_id'], 'driver_hire_settlements_booking_driver_unique');
        });

        Schema::create('driver_settlement_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_hire_settlement_id')->index();
            $table->string('expense_type')->index();
            $table->decimal('claimed_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('LKR');
            $table->string('vendor')->nullable();
            $table->text('description')->nullable();
            $table->json('receipt_files')->nullable();
            $table->date('expense_date')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('review_reason')->nullable();
            $table->uuid('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('driver_iou_advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_hire_settlement_id')->index();
            $table->uuid('booking_id')->index();
            $table->uuid('driver_id')->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('LKR');
            $table->date('issued_date')->nullable()->index();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('issued_by')->nullable()->index();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('driver_logs', function (Blueprint $table) {
            $table->text('particulars')->nullable()->after('end_image');
            $table->string('entry_source')->default('paper_entry')->after('particulars')->index();
            $table->integer('total_km')->nullable()->after('end_km');
            $table->json('attachments')->nullable()->after('entry_source');
        });
    }

    public function down(): void
    {
        Schema::table('driver_logs', function (Blueprint $table) {
            $table->dropColumn(['particulars', 'entry_source', 'total_km', 'attachments']);
        });

        Schema::dropIfExists('driver_iou_advances');
        Schema::dropIfExists('driver_settlement_expenses');
        Schema::dropIfExists('driver_hire_settlements');
        Schema::dropIfExists('driver_batta_rules');
    }
};
