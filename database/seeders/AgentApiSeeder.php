<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Agent\AgentApi;
use Illuminate\Support\Str;

class AgentApiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedAgentApis();
    }

    /**
     * Seed agent API configurations
     * Note: These are shared API configurations, not agent-specific
     */
    private function seedAgentApis(): void
    {
        $apiConfigurations = [
            [
                'title' => 'Production API',
                'description' => 'Main production API for live bookings and operations',
                'api_key' => $this->generateApiKey('production'),
            ],
            [
                'title' => 'Testing API',
                'description' => 'Testing environment API for development and testing purposes',
                'api_key' => $this->generateApiKey('testing'),
            ],
            [
                'title' => 'Mobile App API',
                'description' => 'Dedicated API for mobile application integration',
                'api_key' => $this->generateApiKey('mobile'),
            ],
            [
                'title' => 'Webhook API',
                'description' => 'Real-time webhook notifications for booking status updates',
                'api_key' => $this->generateApiKey('webhook'),
            ],
            [
                'title' => 'Analytics API',
                'description' => 'Advanced analytics and reporting API access',
                'api_key' => $this->generateApiKey('analytics'),
            ],
            [
                'title' => 'Bulk Booking API',
                'description' => 'High-volume bulk booking API for corporate clients',
                'api_key' => $this->generateApiKey('bulk'),
            ],
        ];

        foreach ($apiConfigurations as $apiConfig) {
            AgentApi::updateOrCreate(
                [
                    'title' => $apiConfig['title'],
                ],
                [
                    'description' => $apiConfig['description'],
                    'api_key' => $apiConfig['api_key'],
                ]
            );
        }

        $this->command->info('Agent APIs seeded successfully!');
    }

    /**
     * Generate a unique API key for an API type
     */
    private function generateApiKey(string $apiType): string
    {
        $prefix = strtolower($apiType);
        $randomString = Str::random(32);
        
        return "{$prefix}_api_{$randomString}";
    }
}
