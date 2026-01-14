<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // Use UUIDs consistently with the rest of the project
            $table->uuid('id')->primary();

            // Standard Laravel notification fields
            $table->string('type');
            $table->string('notifiable_type');
            $table->uuid('notifiable_id');
            $table->json('data');
            $table->timestamp('read_at')->nullable();

            // Audit fields
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();

            $table->timestamps();

            // Indexes to speed up common queries
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('read_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
