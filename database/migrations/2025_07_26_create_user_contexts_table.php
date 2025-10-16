<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::dropIfExists('user_contexts');
        Schema::create('user_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('context_type'); // 'customer', 'vehicle_owner', 'staff', 'agent'
            $table->uuid('context_id');     // ID of the related context model
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['user_id', 'context_type']);
            $table->index(['context_type', 'context_id']);
            
            // Ensure unique active context per user per type
            $table->unique(['user_id', 'context_type', 'is_active'], 'unique_active_user_context');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_contexts');
    }
};
