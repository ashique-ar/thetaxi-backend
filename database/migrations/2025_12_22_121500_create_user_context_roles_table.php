<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_context_roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_context_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->unique(['user_context_id', 'role_id'], 'user_context_role_unique');
            $table->index('user_context_id');

            // Foreign key to roles table
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_context_roles');
    }
};
