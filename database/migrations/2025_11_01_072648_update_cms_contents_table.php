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
        Schema::table('cms_contents', function (Blueprint $table) {
            // Fix unique constraints that shouldn't be unique
            $table->dropUnique(['author']);
            $table->dropUnique(['thumbnail']);
            
            // Modify existing columns
            $table->string('author')->nullable()->change();
            $table->string('thumbnail')->nullable()->change();
            
            // Add new columns for better CMS functionality (check if they don't exist)
            if (!Schema::hasColumn('cms_contents', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('body');
            }
            if (!Schema::hasColumn('cms_contents', 'status')) {
                $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->after('published_at');
            }
            if (!Schema::hasColumn('cms_contents', 'excerpt')) {
                $table->text('excerpt')->nullable()->after('status');
            }
            if (!Schema::hasColumn('cms_contents', 'custom_fields')) {
                $table->json('custom_fields')->nullable()->after('excerpt');
            }
            if (!Schema::hasColumn('cms_contents', 'featured_image')) {
                $table->string('featured_image')->nullable()->after('custom_fields');
            }
            if (!Schema::hasColumn('cms_contents', 'gallery_images')) {
                $table->json('gallery_images')->nullable()->after('featured_image');
            }
            if (!Schema::hasColumn('cms_contents', 'views_count')) {
                $table->integer('views_count')->default(0)->after('gallery_images');
            }
            if (!Schema::hasColumn('cms_contents', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('views_count');
            }
            if (!Schema::hasColumn('cms_contents', 'allow_comments')) {
                $table->boolean('allow_comments')->default(true)->after('is_featured');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table) {
            // Remove added columns
            $columnsToRemove = [
                'published_at', 'status', 'excerpt', 'custom_fields',
                'featured_image', 'gallery_images', 'views_count',
                'is_featured', 'allow_comments'
            ];
            
            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('cms_contents', $column)) {
                    $table->dropColumn($column);
                }
            }
            
            // Add back unique constraints (if needed for rollback)
            $table->string('author')->unique()->change();
            $table->string('thumbnail')->unique()->change();
        });
    }
};
