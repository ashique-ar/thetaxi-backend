<?php

namespace Database\Seeders;

use App\Services\DefaultFormConfigService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RentalServiceFormLocationOptionsSeeder extends Seeder
{
    public function run(): void
    {
        $fields = DefaultFormConfigService::getDefaults('self_drive');

        if (Schema::hasTable('service_types')) {
            DB::table('service_types')->whereIn('code', ['self_drive', 'with_driver'])->get()
                ->each(function ($service) use ($fields): void {
                    $config = json_decode($service->form_config ?? '[]', true) ?: [];
                    $storedFields = isset($config['fields']) ? $config['fields'] : $config;
                    $storedFields['pickup_location'] = array_merge(
                        $fields['pickup_location'],
                        $storedFields['pickup_location'] ?? [],
                        ['options' => $fields['pickup_location']['options']],
                    );
                    DB::table('service_types')->where('id', $service->id)->update([
                        'form_config' => json_encode(isset($config['fields']) ? array_merge($config, ['fields' => $storedFields]) : $storedFields),
                        'updated_at' => now(),
                    ]);
                });
        }

        if (Schema::hasTable('service_form_configs')) {
            foreach (['self_drive', 'with_driver'] as $code) {
                $existing = DB::table('service_form_configs')->where('service_code', $code)->first();
                $values = ['config' => json_encode(['fields' => $fields]), 'is_active' => true, 'updated_at' => now()];
                $existing
                    ? DB::table('service_form_configs')->where('service_code', $code)->update($values)
                    : DB::table('service_form_configs')->insert(array_merge($values, [
                        'id' => (string) Str::uuid(), 'service_code' => $code, 'created_at' => now(),
                    ]));
            }
        }
    }
}
