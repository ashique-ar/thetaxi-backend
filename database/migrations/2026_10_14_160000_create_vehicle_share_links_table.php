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
        Schema::create('vehicle_share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id')->index();
            $table->string('token', 64)->unique()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_share_links');
    }
};
