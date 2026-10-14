<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('website_settings')
            ->where('type', 'feature_hr_attendance_ingestion_enabled')
            ->whereNull('company_id')
            ->exists();

        if (!$exists) {
            DB::table('website_settings')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'feature_hr_attendance_ingestion_enabled',
                'company_id' => null,
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep this operational toggle on rollback; operators may have changed it.
    }
};
