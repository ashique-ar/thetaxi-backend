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
        Schema::create('driver_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_id');
            $table->uuid('booking_id');
            $table->uuid('parent_assignment_id')->nullable()->comment('For concurrent assignments');
            
            // Assignment details
            $table->string('customer_name');
            $table->string('service_type');
            $table->datetime('assigned_from');
            $table->datetime('assigned_to');
            $table->enum('assignment_type', ['primary', 'concurrent', 'override'])->default('primary');
            $table->enum('status', ['active', 'completed', 'cancelled', 'pending_approval'])->default('active');
            
            // Overlap and concurrent assignment details
            $table->enum('overlap_type', ['rest_window', 'partial_availability', 'override'])->nullable();
            $table->json('overlap_details')->nullable()->comment('Details about the overlap period');
            
            // Approval workflow
            $table->boolean('requires_approval')->default(false);
            $table->uuid('approved_by')->nullable();
            $table->datetime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->json('override_reasons')->nullable();
            
            // Manual confirmation tracking
            $table->boolean('manually_confirmed')->default(false);
            $table->uuid('confirmed_by')->nullable();
            $table->datetime('confirmed_at')->nullable();
            $table->string('confirmation_method')->nullable()->comment('phone, whatsapp, sms, etc');
            $table->text('confirmation_notes')->nullable();
            
            // Assignment tracking
            $table->uuid('assigned_by');
            $table->datetime('actual_start')->nullable();
            $table->datetime('actual_end')->nullable();
            $table->text('assignment_notes')->nullable();
            
            // Driver-specific fields
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->boolean('overtime_applicable')->default(false);
            $table->json('special_requirements')->nullable()->comment('Special requirements for this assignment');
            
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['driver_id', 'assigned_from', 'assigned_to']);
            $table->index(['booking_id']);
            $table->index(['status']);
            $table->index(['requires_approval']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_assignments');
    }
};
