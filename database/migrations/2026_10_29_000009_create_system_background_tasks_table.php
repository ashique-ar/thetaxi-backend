<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_background_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('label');
            $table->uuid('related_id')->nullable()->index();
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_background_tasks');
    }
};
