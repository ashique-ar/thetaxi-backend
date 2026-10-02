<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_context_permission_grants', function (Blueprint $table): void {
            $table->uuid('user_context_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->primary(['user_context_id', 'permission_id'], 'user_context_permission_grants_primary');
            $table->foreign('user_context_id')->references('id')->on('user_contexts')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('user_direct_permission_grants', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->primary(['user_id', 'permission_id'], 'user_direct_permission_grants_primary');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_direct_permission_grants');
        Schema::dropIfExists('user_context_permission_grants');
    }
};
