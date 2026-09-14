<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('corporate_remittance_allocations', function (Blueprint $table) {
            $table->dropUnique(['remittance_id', 'settlement_id']);
            $table->index(['remittance_id', 'settlement_id']);
        });
        Schema::create('financial_allocation_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_remittance_allocation_id')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 1000);
            $table->uuid('reversed_by')->nullable();
            $table->timestamp('reversed_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_allocation_reversals');
        Schema::table('corporate_remittance_allocations', function (Blueprint $table) {
            $table->dropIndex(['remittance_id', 'settlement_id']);
            $table->unique(['remittance_id', 'settlement_id']);
        });
    }
};
