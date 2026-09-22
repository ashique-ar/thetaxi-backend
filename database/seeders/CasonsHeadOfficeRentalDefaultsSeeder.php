<?php

namespace Database\Seeders;

use App\Services\DefaultFormConfigService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CasonsHeadOfficeRentalDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $pickup = DefaultFormConfigService::getDefaults('self_drive')['pickup_location'];

        if (Schema::hasTable('service_types')) {
            DB::table('service_types')->whereIn('code', ['self_drive', 'with_driver'])->get()
                ->each(function ($service) use ($pickup): void {
                    $config = json_decode($service->form_config ?? '[]', true) ?: [];
                    $fields = $config['fields'] ?? $config;
                    $fields['pickup_location'] = array_merge($fields['pickup_location'] ?? [], $pickup);
                    DB::table('service_types')->where('id', $service->id)->update([
                        'form_config' => json_encode(isset($config['fields']) ? array_merge($config, ['fields' => $fields]) : $fields),
                        'updated_at' => now(),
                    ]);
                });
        }

        if (Schema::hasTable('service_form_configs')) {
            foreach (['self_drive', 'with_driver'] as $code) {
                $record = DB::table('service_form_configs')->where('service_code', $code)->first();
                if ($record) {
                    $config = json_decode($record->config ?? '[]', true) ?: [];
                    $fields = $config['fields'] ?? $config;
                    $fields['pickup_location'] = array_merge($fields['pickup_location'] ?? [], $pickup);
                    DB::table('service_form_configs')->where('id', $record->id)->update([
                        'config' => json_encode(isset($config['fields']) ? array_merge($config, ['fields' => $fields]) : $fields),
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                DB::table('service_form_configs')->insert([
                    'id' => (string) Str::uuid(),
                    'service_code' => $code,
                    'config' => json_encode(['fields' => DefaultFormConfigService::getDefaults($code)]),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
