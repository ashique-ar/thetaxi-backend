<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $query = DB::table('website_settings')
                ->where('type', $setting['type'])
                ->whereNull('company_id');

            if ($query->exists()) {
                $query->update([
                    'value' => $setting['value'],
                    'updated_at' => $setting['updated_at'],
                ]);

                continue;
            }

            DB::table('website_settings')->insert([
                'id' => (string) Str::uuid(),
                'type' => $setting['type'],
                'company_id' => null,
                'value' => $setting['value'],
                'created_at' => $setting['created_at'],
                'updated_at' => $setting['updated_at'],
            ]);
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
