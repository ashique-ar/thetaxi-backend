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
        // Create contact page content type
        DB::table('cms_content_types')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'title' => 'Contact Page',
            'slug' => 'contact-page',
            'description' => 'Contact page sections and information',
            'icon' => 'fas fa-envelope',
            'display_order' => 10,
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
        DB::table('cms_content_types')->where('slug', 'contact-page')->delete();
    }
};