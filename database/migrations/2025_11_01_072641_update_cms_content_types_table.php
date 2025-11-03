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
        Schema::table('cms_content_types', function (Blueprint $table) {
            // Fix description unique constraint
            $table->dropUnique(['description']);
            
            // Add new columns for better CMS functionality (check if they don't exist)
            if (!Schema::hasColumn('cms_content_types', 'icon')) {
                $table->string('icon')->nullable()->after('description');
            }
            if (!Schema::hasColumn('cms_content_types', 'template_config')) {
                $table->json('template_config')->nullable()->after('icon');
            }
            if (!Schema::hasColumn('cms_content_types', 'display_order')) {
                $table->integer('display_order')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('cms_content_types', 'url_prefix')) {
                $table->string('url_prefix')->nullable()->after('display_order');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cms_content_types', function (Blueprint $table) {
            // Remove added columns
            if (Schema::hasColumn('cms_content_types', 'icon')) {
                $table->dropColumn('icon');
            }
            if (Schema::hasColumn('cms_content_types', 'template_config')) {
                $table->dropColumn('template_config');
            }
            if (Schema::hasColumn('cms_content_types', 'display_order')) {
                $table->dropColumn('display_order');
            }
            if (Schema::hasColumn('cms_content_types', 'url_prefix')) {
                $table->dropColumn('url_prefix');
            }
            
            // Add back unique constraint on description (if needed for rollback)
            $table->text('description')->unique()->change();
        });
    }
};
