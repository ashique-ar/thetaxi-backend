<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_insurance_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_insurance_id')->constrained('vehicle_insurances')->cascadeOnDelete();
            $table->string('claim_number')->unique();
            $table->date('incident_date');
            $table->date('filed_date')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('claimed_amount', 12, 2)->nullable();
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->text('description');
            $table->text('resolution_notes')->nullable();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['vehicle_insurance_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_insurance_claims');
    }
};
