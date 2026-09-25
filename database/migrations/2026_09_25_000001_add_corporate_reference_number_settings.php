<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->string('booking_number_prefix', 12)->default('BK');
            $table->unsignedBigInteger('booking_number_start')->default(1);
            $table->unsignedInteger('booking_number_increment')->default(1);
            $table->unsignedTinyInteger('booking_number_digits')->default(6);
            $table->string('booking_item_number_prefix', 12)->default('TR');
            $table->unsignedBigInteger('booking_item_number_start')->default(1);
            $table->unsignedInteger('booking_item_number_increment')->default(1);
            $table->unsignedTinyInteger('booking_item_number_digits')->default(4);
            $table->timestamp('reference_numbers_seeded_at')->nullable();
        });

        Schema::create('corporate_reference_sequences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('corporate_id');
            $table->string('reference_type', 24);
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
            $table->unique(['corporate_id', 'reference_type'], 'corporate_reference_sequences_scope_unique');
            $table->foreign('corporate_id')->references('id')->on('corporates')->cascadeOnDelete();
        });

        Schema::table('booking_items', function (Blueprint $table): void {
            $table->string('item_code', 64)->nullable()->index();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique(['booking_number']);
            $table->unique(['corporate_account_id', 'booking_number'], 'bookings_company_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropUnique('bookings_company_number_unique');
            $table->unique('booking_number');
        });
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->dropIndex(['item_code']);
            $table->dropColumn('item_code');
        });
        Schema::dropIfExists('corporate_reference_sequences');
        Schema::table('corporates', function (Blueprint $table): void {
            $table->dropColumn([
                'booking_number_prefix', 'booking_number_start', 'booking_number_increment', 'booking_number_digits',
                'booking_item_number_prefix', 'booking_item_number_start', 'booking_item_number_increment', 'booking_item_number_digits',
                'reference_numbers_seeded_at',
            ]);
        });
    }
};
