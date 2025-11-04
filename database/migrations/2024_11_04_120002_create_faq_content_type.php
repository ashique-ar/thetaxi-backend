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
        // Create FAQ content type
        DB::table('cms_content_types')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'title' => 'FAQ',
            'slug' => 'faq',
            'description' => 'Frequently Asked Questions',
            'icon' => 'fas fa-question-circle',
            'display_order' => 6,
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
        DB::table('cms_content_types')->where('slug', 'faq')->delete();
    }
};