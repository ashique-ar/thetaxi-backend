<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_duplicate_decisions', function (Blueprint $table): void {
            $table->foreignUuid('canonical_customer_id')->nullable()->after('other_customer_id')
                ->constrained('customers')->restrictOnDelete();
        });

        Schema::create('customer_user_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('source_customer_id')->unique()->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('merged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_user_aliases') && DB::table('customer_user_aliases')->exists()) {
            throw new RuntimeException('Refusing to remove customer portal aliases. Restore those customer identities first.');
        }
        if (Schema::hasTable('customer_duplicate_decisions') && DB::table('customer_duplicate_decisions')->whereNotNull('canonical_customer_id')->exists()) {
            throw new RuntimeException('Refusing to remove customer merge history. Export or restore merged customer records first.');
        }

        Schema::dropIfExists('customer_user_aliases');
        Schema::table('customer_duplicate_decisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('canonical_customer_id'));
    }
};
