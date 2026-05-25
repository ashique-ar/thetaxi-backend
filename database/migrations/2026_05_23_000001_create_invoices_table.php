<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number')->unique();
            $table->uuid('booking_id');
            $table->uuid('customer_id')->nullable();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();

            // Financial
            $table->string('currency', 10)->default('LKR');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            // Line items stored as JSON
            $table->json('line_items');

            // Status
            $table->enum('status', ['draft', 'issued', 'paid', 'void'])->default('issued');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Document
            $table->string('pdf_path')->nullable();
            $table->string('pdf_disk')->nullable()->default('local');

            // Payment terms & notes
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();

            // Meta
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
        });

        // Back-fill invoice_number on bookings that complete after this migration
        // (existing bookings keep their null invoice_number until manually re-triggered)
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
