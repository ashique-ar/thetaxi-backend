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
        Schema::dropIfExists('booking_status_histories');
        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('booking_id');
            $table->string('old_status');
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['booking_id', 'changed_at']);
            $table->index('new_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_status_histories');
    }
};
