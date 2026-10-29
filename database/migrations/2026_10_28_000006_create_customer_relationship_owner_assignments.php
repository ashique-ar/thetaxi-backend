<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_relationship_owner_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('owner_sales_profile_id')->nullable()->constrained('sales_profiles')->restrictOnDelete();
            $table->foreignUuid('assigned_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestampTz('effective_from');
            $table->timestampTz('ended_at')->nullable();
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'customer_id', 'ended_at'], 'customer_relationship_owner_current_idx');
            $table->index(['owner_sales_profile_id', 'ended_at'], 'customer_relationship_owner_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_relationship_owner_assignments');
    }
};
