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
        Schema::create('inquiry_service_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id')->nullable()->index();
            $table->uuid('inquiry_form_id')->nullable()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code')->unique();
            $table->string('inquiry_type')->nullable();
            $table->string('status')->default('draft');
            $table->json('content')->nullable();
            $table->json('settings')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_keywords')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('service_type_id')
                ->references('id')
                ->on('service_types')
                ->nullOnDelete();

            $table->foreign('inquiry_form_id')
                ->references('id')
                ->on('inquiry_forms')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiry_service_pages');
    }
};
