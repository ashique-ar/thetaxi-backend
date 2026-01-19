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
        Schema::create('inquiry_form_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inquiry_form_id')->index();
            $table->string('name');
            $table->string('label');
            $table->string('type');
            $table->string('icon')->nullable();
            $table->string('placeholder')->nullable();
            $table->text('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->string('validation_rules')->nullable();
            $table->json('options')->nullable();
            $table->string('default_value')->nullable();
            $table->string('width')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('conditional_logic')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['inquiry_form_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiry_form_fields');
    }
};
