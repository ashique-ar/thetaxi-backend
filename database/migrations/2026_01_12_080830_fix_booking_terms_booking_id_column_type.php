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
        Schema::table('booking_terms', function (Blueprint $table) {
            // Drop the existing foreign key constraint if it exists
            $table->dropIndex(['booking_id','terms_and_condition_id']);
            
            // Change booking_id from unsignedBigInteger to uuid
            $table->uuid('booking_id')->change();
            
            // Recreate the unique constraint
            $table->unique(['booking_id','terms_and_condition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_terms', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropIndex(['booking_id','terms_and_condition_id']);
            
            // Revert booking_id back to unsignedBigInteger
            $table->unsignedBigInteger('booking_id')->change();
            
            // Recreate the unique constraint
            $table->unique(['booking_id','terms_and_condition_id']);
        });
    }
};
