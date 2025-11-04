<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create navigation menu content type
        DB::table('cms_content_types')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'title' => 'Navigation Menu',
            'slug' => 'navigation-menu',
            'description' => 'Website navigation menu items',
            'icon' => 'fas fa-bars',
            'display_order' => 7,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('cms_content_types')->where('slug', 'navigation-menu')->delete();
    }
};