<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'type' => 'feature_corporate_management_enabled',
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'feature_vehicle_return_management_enabled',
                'value' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('website_settings')->updateOrInsert(
                ['type' => $setting['type'], 'company_id' => null],
                $setting
            );
        }
    }

    public function down(): void
    {
        DB::table('website_settings')
            ->whereIn('type', [
                'feature_corporate_management_enabled',
                'feature_vehicle_return_management_enabled',
            ])
            ->whereNull('company_id')
            ->delete();
    }
};
