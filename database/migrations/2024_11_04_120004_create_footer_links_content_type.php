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
        // Create footer links content type
        DB::table('cms_content_types')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'title' => 'Footer Links',
            'slug' => 'footer-links',
            'description' => 'Footer section links and content',
            'icon' => 'fas fa-link',
            'display_order' => 8,
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
        DB::table('cms_content_types')->where('slug', 'footer-links')->delete();
    }
};