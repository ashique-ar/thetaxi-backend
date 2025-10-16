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
        Schema::table('vehicles', function (Blueprint $table) {
            // Current availability status
            $table->enum('availability_status', ['available', 'booked', 'long_term', 'resting', 'maintenance', 'offline'])
                  ->default('available')
                  ->after('description');
            
            // Current assignment details
            $table->uuid('current_booking_id')->nullable()->after('availability_status');
            $table->string('current_customer_name')->nullable()->after('current_booking_id');
            $table->string('current_service_type')->nullable()->after('current_customer_name');
            $table->datetime('current_assignment_from')->nullable()->after('current_service_type');
            $table->datetime('current_assignment_to')->nullable()->after('current_assignment_from');
            $table->boolean('is_long_term_assignment')->default(false)->after('current_assignment_to');
            
            // Override and approval settings
            $table->boolean('override_allowed')->default(true)->after('is_long_term_assignment');
            $table->boolean('requires_approval_for_override')->default(true)->after('override_allowed');
            $table->boolean('concurrent_assignment_possible')->default(false)->after('requires_approval_for_override');
            
            // Self-driven compatibility
            $table->boolean('is_self_driven_compatible')->default(true)->after('concurrent_assignment_possible');
            $table->boolean('has_automatic_transmission')->default(false)->after('is_self_driven_compatible');
            $table->boolean('has_power_steering')->default(true)->after('has_automatic_transmission');
            $table->boolean('has_gps_enabled')->default(false)->after('has_power_steering');
            
            // Maintenance and rest scheduling
            $table->json('rest_windows')->nullable()->after('has_gps_enabled')->comment('Array of rest time windows');
            $table->json('maintenance_schedule')->nullable()->after('rest_windows')->comment('Scheduled maintenance times');
            
            // Last confirmation details
            $table->datetime('last_availability_confirmed_at')->nullable()->after('maintenance_schedule');
            $table->uuid('last_confirmed_by')->nullable()->after('last_availability_confirmed_at');
            
            // Assignment history tracking
            $table->integer('total_assignments_count')->default(0)->after('last_confirmed_by');
            $table->datetime('last_assignment_end')->nullable()->after('total_assignments_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'availability_status',
                'current_booking_id',
                'current_customer_name',
                'current_service_type',
                'current_assignment_from',
                'current_assignment_to',
                'is_long_term_assignment',
                'override_allowed',
                'requires_approval_for_override',
                'concurrent_assignment_possible',
                'is_self_driven_compatible',
                'has_automatic_transmission',
                'has_power_steering',
                'has_gps_enabled',
                'rest_windows',
                'maintenance_schedule',
                'last_availability_confirmed_at',
                'last_confirmed_by',
                'total_assignments_count',
                'last_assignment_end',
            ]);
        });
    }
};
