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
        Schema::create('popups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('content');
            $table->string('image', 500)->nullable();
            $table->string('cta_text', 100)->nullable();
            $table->string('cta_link', 500)->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->enum('display_frequency', ['always', 'once_per_session', 'once_per_day'])->default('always');
            $table->json('target_pages')->default('["all"]');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['is_active', 'start_date', 'end_date'], 'idx_popups_active');
            $table->index('priority', 'idx_popups_priority');
            $table->index('created_user_id');
            $table->index('updated_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('popups');
    }
};
