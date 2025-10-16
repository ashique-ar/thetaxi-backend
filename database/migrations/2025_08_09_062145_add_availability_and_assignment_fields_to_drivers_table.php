<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Current availability status
            $table->enum('availability_status', ['available', 'booked', 'long_term', 'resting', 'on_leave', 'offline'])
                  ->default('available')
                  ->after('remarks');
            
            // Current assignment details
            $table->uuid('current_booking_id')->nullable()->after('availability_status');
            $table->string('current_customer_name')->nullable()->after('current_booking_id');
            $table->string('current_service_type')->nullable()->after('current_customer_name');
            $table->datetime('current_assignment_from')->nullable()->after('current_service_type');
            $table->datetime('current_assignment_to')->nullable()->after('current_assignment_from');
            $table->boolean('is_long_term_assignment')->default(false)->after('current_assignment_to');
            
            // Default vehicle assignment
            $table->uuid('default_vehicle_id')->nullable()->after('is_long_term_assignment');
            
            // Override and approval settings
            $table->boolean('override_allowed')->default(true)->after('default_vehicle_id');
            $table->boolean('requires_approval_for_override')->default(true)->after('override_allowed');
            $table->boolean('concurrent_assignment_possible')->default(false)->after('requires_approval_for_override');
            
            // Working schedule and rest windows
            $table->json('rest_windows')->nullable()->after('concurrent_assignment_possible')->comment('Array of rest time windows');
            $table->json('working_schedule')->nullable()->after('rest_windows')->comment('Regular working hours');
            $table->json('leave_schedule')->nullable()->after('working_schedule')->comment('Scheduled leave times');
            
            // Last confirmation details
            $table->datetime('last_availability_confirmed_at')->nullable()->after('leave_schedule');
            $table->uuid('last_confirmed_by')->nullable()->after('last_availability_confirmed_at');
            $table->string('last_confirmation_method')->nullable()->after('last_confirmed_by')->comment('phone, whatsapp, sms, etc');
            
            // Assignment history tracking
            $table->integer('total_assignments_count')->default(0)->after('last_confirmation_method');
            $table->datetime('last_assignment_end')->nullable()->after('total_assignments_count');
            $table->decimal('average_rating', 3, 2)->nullable()->after('last_assignment_end');
            
            // Emergency contact
            $table->string('emergency_contact_name')->nullable()->after('average_rating');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            
            $table->dropColumn([
                'availability_status',
                'current_booking_id',
                'current_customer_name',
                'current_service_type',
                'current_assignment_from',
                'current_assignment_to',
                'is_long_term_assignment',
                'default_vehicle_id',
                'override_allowed',
                'requires_approval_for_override',
                'concurrent_assignment_possible',
                'rest_windows',
                'working_schedule',
                'leave_schedule',
                'last_availability_confirmed_at',
                'last_confirmed_by',
                'last_confirmation_method',
                'total_assignments_count',
                'last_assignment_end',
                'average_rating',
                'emergency_contact_name',
                'emergency_contact_phone',
            ]);
        });
    }
};
