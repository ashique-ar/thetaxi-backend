<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('website_settings')
            ->where('type', 'email_header_subtitle')
            ->whereNull('company_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('website_settings')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'email_header_subtitle',
            'company_id' => null,
            'value' => 'Premium Taxi Service',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('website_settings')
            ->where('type', 'email_header_subtitle')
            ->whereNull('company_id')
            ->delete();
    }
};
