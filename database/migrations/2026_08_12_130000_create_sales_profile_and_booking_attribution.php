<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('sales_code', 80);
            $table->string('status', 20)->default('active');
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'sales_code']);
            $table->index(['staff_id', 'effective_from', 'effective_until']);
        });

        Schema::create('sales_reporting_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('manager_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('member_sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->string('team_code', 80)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['manager_sales_profile_id', 'effective_from', 'effective_until'], 'sales_reporting_manager_effective_idx');
            $table->index(['member_sales_profile_id', 'effective_from', 'effective_until'], 'sales_reporting_member_effective_idx');
        });

        Schema::create('sales_booking_attributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->unique()->constrained('bookings')->restrictOnDelete();
            $table->uuid('root_attribution_id')->nullable()->index();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('acquisition_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('collection_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('commission_category', 20);
            $table->string('business_classification', 30);
            $table->string('classification_source', 80);
            $table->timestamp('secured_at');
            $table->decimal('contract_value_source', 20, 4);
            $table->string('source_currency', 3);
            $table->decimal('contract_value_lkr', 20, 4)->nullable();
            $table->decimal('fx_rate_to_lkr', 20, 10)->nullable();
            $table->timestamp('fx_rate_at')->nullable();
            $table->string('new_customer_status', 20)->default('pending');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'secured_at']);
            $table->index(['acquisition_sales_profile_id', 'secured_at'], 'sales_attribution_acquisition_idx');
            $table->index(['collection_sales_profile_id', 'secured_at'], 'sales_attribution_collection_idx');
        });

        // Postgres cannot resolve a self-referencing foreign key added inside the
        // same Schema::create() as a table-level ALTER ADD CONSTRAINT — the primary
        // key it needs to reference is not yet visible to that statement. Adding it
        // in a separate Schema::table() call after creation avoids the ordering issue.
        Schema::table('sales_booking_attributions', function (Blueprint $table) {
            $table->foreign('root_attribution_id')->references('id')->on('sales_booking_attributions')->restrictOnDelete();
        });

        Schema::create('sales_booking_attribution_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attribution_id')->constrained('sales_booking_attributions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event_type', 60);
            $table->string('field_name', 80)->nullable();
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->foreignUuid('from_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('to_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->timestamp('effective_at');
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 160);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['attribution_id', 'version']);
            $table->unique(['attribution_id', 'idempotency_key']);
        });

        Schema::create('sales_attribution_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->string('exception_type', 80);
            $table->string('status', 20)->default('open');
            $table->text('details');
            $table->timestamp('detected_at');
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'exception_type', 'status'], 'sales_attribution_open_exception_unique');
            $table->index(['status', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_attribution_exceptions');
        Schema::dropIfExists('sales_booking_attribution_events');
        Schema::dropIfExists('sales_booking_attributions');
        Schema::dropIfExists('sales_reporting_assignments');
        Schema::dropIfExists('sales_profiles');
    }
};
