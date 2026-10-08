<?php

namespace Database\Seeders;

class TheTaxiTenantDecisionSeeder extends TenantDecisionProfileSeeder
{
    protected function profile(): array
    {
        return [
            'name' => 'TheTaxi',
            'service_scope' => 'Taxi and chauffeur-driven transport services in Sri Lanka.',
        ];
    }
}
