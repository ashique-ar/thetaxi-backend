<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function defaults(): array
    {
        return [
            'airport_transfers' => ['Free Cancellations', 'Meet & Greet included', 'No Hidden Charges'],
            'point_to_point' => ['Point-to-Point Transfers', 'Reliable Service', 'Professional Drivers', 'Real-time GPS Tracking', 'Competitive Pricing'],
            'ride_now' => ['Free Cancellations', 'No Hidden Charges'],
            'day_rental' => ['No Hidden Charges', 'Free Cancellations', 'Flexible Drop-off', 'Insurance Included'],
            'self_drive' => ['No Hidden Charges', 'Free Cancellations', 'Flexible Drop-off', 'Insurance Included', 'Self Drive Freedom'],
            'with_driver' => ['No Hidden Charges', 'Free Cancellations', 'Professional Driver', 'Flexible Drop-off', 'Insurance Included'],
            'wedding_hire' => ['Special Occasion Service', 'Professional Drivers', 'Decorated Vehicles', 'Flexible Timing', 'Premium Service'],
            'corporate' => ['Corporate Accounts', 'Contract Pricing', 'Reliable Service', 'Professional Drivers', 'Expense Tracking'],
        ];
    }

    public function up(): void
    {
        foreach ($this->defaults() as $code => $features) {
            DB::table('service_types')->where('code', $code)->whereNull('deleted_at')
                ->orderBy('id')->get(['id', 'form_config'])->each(function ($service) use ($features): void {
                    $config = json_decode((string) $service->form_config, true) ?: [];
                    if (array_key_exists('service_features', $config)) {
                        return;
                    }
                    $config['service_features'] = $features;
                    DB::table('service_types')->where('id', $service->id)->update([
                        'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        foreach ($this->defaults() as $code => $features) {
            DB::table('service_types')->where('code', $code)->whereNull('deleted_at')
                ->orderBy('id')->get(['id', 'form_config'])->each(function ($service) use ($features): void {
                    $config = json_decode((string) $service->form_config, true) ?: [];
                    if (($config['service_features'] ?? null) !== $features) {
                        return;
                    }
                    unset($config['service_features']);
                    DB::table('service_types')->where('id', $service->id)->update([
                        'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);
                });
        }
    }
};
