<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('website_settings')
            ->where('type', 'cms_content_placeholder_image')
            ->whereNull('company_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('website_settings')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'cms_content_placeholder_image',
            'company_id' => null,
            'value' => 'assets/img/default-blog.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('website_settings')
            ->where('type', 'cms_content_placeholder_image')
            ->whereNull('company_id')
            ->delete();
    }
};
