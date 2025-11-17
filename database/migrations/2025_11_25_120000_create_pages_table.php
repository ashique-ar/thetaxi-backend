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
        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->text('excerpt')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('template')->default('default');
            $table->uuid('parent_id')->nullable();
            $table->enum('status', ['draft', 'published', 'private', 'archived'])->default('draft');
            $table->enum('visibility', ['public', 'private', 'password'])->default('public');
            $table->string('password')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_keywords')->nullable();
            $table->json('custom_fields')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_homepage')->default(false);
            $table->boolean('is_in_menu')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('page_views')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['slug']);
            $table->index(['status', 'is_active']);
            $table->index(['visibility']);
            $table->index(['parent_id']);
            $table->index(['is_homepage']);
            $table->index(['is_in_menu']);
            $table->index(['is_featured']);
            $table->index(['published_at']);
            $table->index(['sort_order']);
            $table->index(['created_user_id']);
            $table->index(['updated_user_id']);
            
            // Composite indexes for better query performance
            $table->index(['status', 'is_active', 'published_at']);
            $table->index(['visibility', 'status', 'is_active']);
            $table->index(['parent_id', 'sort_order']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};