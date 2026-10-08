<?php

namespace Database\Seeders;

class CasonsTenantDecisionSeeder extends TenantDecisionProfileSeeder
{
    protected function profile(): array
    {
        return [
            'name' => 'Casons',
            'service_scope' => 'Car rental services in Sri Lanka, including self-drive and with-driver rentals.',
        ];
    }
}
