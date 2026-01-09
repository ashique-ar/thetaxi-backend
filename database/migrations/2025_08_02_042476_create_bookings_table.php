<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('bookings');
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->index();
            $table->string('invoice_number')->nullable();
            $table->string('log_code')->nullable();
            $table->uuid('service_type_id')->index();
            $table->uuid('vehicle_group_id')->index();
            $table->uuid('vehicle_id')->nullable()->index();
            $table->uuid('driver_id')->nullable()->index();

            $table->uuid('vip_id')->nullable()->index();
            $table->dateTime('booking_date')->nullable();
            $table->dateTime('from_date')->nullable();
            $table->dateTime('to_date')->nullable();
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();

            // $table->jsonb('pickup_location')->nullable();
            // $table->jsonb('dropoff_location')->nullable();

            $table->decimal('total_estimated', 12, 2)->nullable();
            $table->decimal('total_actual', 12, 2)->nullable();

            $table->string('created_from')->default('web');
            $table->boolean('confirmed')->default(false);

            $table->string('third_party_ref')->nullable();

            // Enhanced location fields
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->string('pickup_landmark')->nullable();
            $table->decimal('dropoff_latitude', 10, 7)->nullable();
            $table->decimal('dropoff_longitude', 10, 7)->nullable();
            $table->string('dropoff_landmark')->nullable();

            // Service details
            $table->boolean('is_self_driven')->default(false);
            $table->integer('passenger_count')->default(1);
            $table->integer('luggage_count')->nullable();
            $table->text('special_requirements')->nullable();

            // Enhanced pricing fields
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('driver_cost', 12, 2)->default(0);
            $table->decimal('distance_cost', 12, 2)->default(0);
            $table->decimal('addons_cost', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('LKR');

            // Payment details
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending');
            $table->string('payment_reference')->nullable();

            // Corporate booking fields
            $table->boolean('is_corporate_booking')->default(false);
            $table->uuid('corporate_account_id')->nullable();
            $table->string('cost_center')->nullable();
            $table->string('project_code')->nullable();
            $table->string('employee_id')->nullable();

            $table->jsonb('review_notes')->nullable();

            // Recurring booking fields
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_pattern', ['daily', 'weekly', 'monthly'])->nullable();
            $table->date('recurrence_end_date')->nullable();
            $table->json('recurrence_days')->nullable();

            // Approval workflow
            $table->boolean('requires_approval')->default(false);
            $table->string('approval_status')->default('not_required');
            
            // Enhanced approval fields for full workflow support
            $table->uuid('approval_requested_by')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->text('approval_justification')->nullable();
            $table->enum('approval_priority', ['normal', 'high', 'urgent'])->default('normal');
            
            // Booking source and creation tracking
            $table->uuid('created_by_user_id')->nullable();
            $table->string('booking_source', 50)->default('internal');
            
            // Enhanced status tracking
            $table->string('status')->default('pending')->nullable();
            
            // Override tracking for complete audit trail
            $table->jsonb('override_reasons')->nullable();
            $table->boolean('has_overrides')->default(false);
            $table->json('concurrent_assignments')->nullable();
            
            // Booking workflow state
            $table->string('workflow_step')->nullable();
            $table->jsonb('workflow_data')->nullable();

            // Distance and time tracking
            $table->decimal('estimated_distance', 8, 2)->nullable();
            $table->integer('estimated_duration')->nullable()->comment('Duration in minutes');
            $table->decimal('actual_distance', 8, 2)->nullable();
            $table->integer('actual_duration')->nullable()->comment('Duration in minutes');

            // Charges inclusion flags
            $table->boolean('toll_charges_included')->default(false);
            $table->boolean('fuel_charges_included')->default(true);
            $table->boolean('parking_charges_included')->default(false);

            // Emergency contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relationship')->nullable();

            // Insurance and safety
            $table->string('insurance_type')->nullable();
            $table->json('safety_features_required')->nullable();

            // Notification preferences
            $table->boolean('notification_sms')->default(true);
            $table->boolean('notification_email')->default(true);
            $table->boolean('notification_whatsapp')->default(false);
            $table->boolean('notification_push')->default(true);

            // Booking confirmations and numbers
            $table->string('booking_number')->unique()->nullable();
            $table->string('confirmation_number')->unique()->nullable();

            // Additional timestamps
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Trip tracking
            $table->string('trip_status')->default('not_started')->nullable();
            $table->timestamp('trip_started_at')->nullable();
            $table->timestamp('trip_ended_at')->nullable();
            $table->decimal('current_latitude', 10, 7)->nullable();
            $table->decimal('current_longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();

            // Feedback and ratings
            $table->integer('customer_rating')->nullable()->comment('Rating from 1-5');
            $table->text('customer_feedback')->nullable();
            $table->integer('driver_rating')->nullable()->comment('Rating from 1-5');
            $table->text('driver_feedback')->nullable();

            // Pricing overrides and approval management
            $table->decimal('base_price_override', 12, 2)->nullable();
            $table->text('base_price_override_reason')->nullable();
            $table->uuid('base_price_edited_by')->nullable();
            $table->timestamp('base_price_edited_at')->nullable();

            // Add-on overrides
            $table->json('addon_overrides')->nullable();

            // Discounts
            $table->jsonb('discounts')->nullable();

            // Approval tracking
            $table->uuid('approval_by')->nullable();
            $table->timestamp('approval_at')->nullable();
            $table->text('approval_note')->nullable();

            // Original vs edited totals
            $table->json('original_totals')->nullable();
            $table->json('edited_totals')->nullable();

            // Gamify integration
            $table->decimal('gamify_points_earned', 10, 2)->default(0);
            $table->decimal('gamify_discount_applied', 10, 2)->default(0);

            $table->jsonb('gamify_details')->nullable();

            $table->jsonb('pricing_snapshot')->nullable();
            $table->jsonb('duration_metrics')->nullable();
            $table->jsonb('distance_metrics')->nullable();

            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // Add indexes for performance
            $table->index(['pickup_latitude', 'pickup_longitude']);
            $table->index(['dropoff_latitude', 'dropoff_longitude']);
            $table->index(['is_corporate_booking', 'corporate_account_id']);
            $table->index(['is_recurring', 'recurrence_pattern']);
            $table->index(['requires_approval', 'approval_status']);
            $table->index(['payment_status', 'payment_method']);
            $table->index(['trip_status', 'trip_started_at']);
            $table->index('booking_number');
            $table->index('confirmation_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
