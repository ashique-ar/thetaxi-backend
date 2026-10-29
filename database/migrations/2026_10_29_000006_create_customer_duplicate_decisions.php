<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_duplicate_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('other_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32);
            $table->string('reason', 500);
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['company_id', 'customer_id', 'other_customer_id'], 'customer_duplicate_decision_pair_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_duplicate_decisions') && DB::table('customer_duplicate_decisions')->exists()) {
            throw new \RuntimeException('Refusing to drop reviewed customer-pair decisions. Export or archive them before rolling back.');
        }
        Schema::dropIfExists('customer_duplicate_decisions');
    }
};
