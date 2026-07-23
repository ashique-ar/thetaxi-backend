<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert the new setting with default value (true to maintain backward compatibility)
        DB::table('website_settings')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'include_garage_distance_in_pricing',
            'value' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the setting
        DB::table('website_settings')
            ->where('type', 'include_garage_distance_in_pricing')
            ->delete();
    }
};
