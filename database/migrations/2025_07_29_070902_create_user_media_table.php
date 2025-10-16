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
         Schema::dropIfExists('user_media');
        Schema::create('user_media', function (Blueprint $table) {
             $table->uuid('id')->primary();
            
            // File information
            $table->string('file_name')->comment('Generated unique filename');
            $table->string('original_name')->comment('Original filename from upload');
            $table->string('file_path')->comment('Directory path where file is stored');
            $table->string('full_path')->comment('Complete path including filename');
            $table->string('mime_type')->comment('File MIME type');
            $table->bigInteger('size')->comment('File size in bytes');
            
            // Image-specific fields
            $table->integer('width')->nullable()->comment('Image width in pixels');
            $table->integer('height')->nullable()->comment('Image height in pixels');
            
            // Relationships
            $table->uuid('user_id')->nullable()->comment('User who owns this file');
            
            // File categorization
            $table->string('category')->default('general')->comment('File category');
            $table->boolean('is_image')->default(false)->comment('Whether this file is an image');
            $table->boolean('is_thumbnail')->default(false)->comment('Whether this is a thumbnail version');
            $table->uuid('parent_id')->nullable()->comment('Parent file ID for thumbnails');
            
            // Storage information
            $table->string('storage_type')->default('local')->comment('Storage type (local, s3)');
            $table->json('metadata')->nullable()->comment('Additional metadata');

            $table->boolean('is_active')->default(false)->comment('Whether this is an active file');
           
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
           
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['user_id']);
            $table->index(['category']);
            $table->index(['is_image']);
            $table->index(['is_thumbnail']);
            $table->index(['parent_id']);
            $table->index(['storage_type']);
            $table->index(['created_at']);            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_media');
    }
};
