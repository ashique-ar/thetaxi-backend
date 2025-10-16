<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Agent\Agent;
use App\Models\Agent\AgentApi;
use App\Models\Agent\AgentApiSession;
use Carbon\Carbon;

class AgentApiSessionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedAgentApiSessions();
    }

    /**
     * Seed agent API sessions - connecting agents to APIs they use
     */
    private function seedAgentApiSessions(): void
    {
        $agents = Agent::all();
        $agentApis = AgentApi::all()->keyBy('title');

        foreach ($agents as $agent) {
            // Basic APIs that most agents use
            $basicApis = ['Production API', 'Mobile App API'];
            
            // Premium agents get additional APIs
            $premiumAgents = ['PVS004', 'CFM006', 'WCS007'];
            if (in_array($agent->code, $premiumAgents)) {
                $basicApis = array_merge($basicApis, ['Webhook API', 'Analytics API']);
            }
            
            // Corporate agents get bulk API
            $corporateAgents = ['CFM006', 'ITT003'];
            if (in_array($agent->code, $corporateAgents)) {
                $basicApis[] = 'Bulk Booking API';
            }
            
            // Testing API for some agents
            if (rand(0, 1)) {
                $basicApis[] = 'Testing API';
            }

            foreach ($basicApis as $apiTitle) {
                $agentApi = $agentApis->get($apiTitle);
                if ($agentApi) {
                    // Create multiple sessions per agent-api combination
                    $sessionCount = $this->getSessionCountByApiType($apiTitle);
                    
                    for ($i = 0; $i < $sessionCount; $i++) {
                        $lastAccess = $this->generateRealisticAccessTime($apiTitle, $i);
                        
                        AgentApiSession::create([
                            'agent_id' => $agent->id,
                            'agent_api_id' => $agentApi->id,
                            'last_access' => $lastAccess,
                        ]);
                    }
                }
            }
        }

        $this->command->info('Agent API sessions seeded successfully!');
    }

    /**
     * Get session count based on API type
     */
    private function getSessionCountByApiType(string $apiTitle): int
    {
        return match($apiTitle) {
            'Production API' => rand(15, 30), // High usage
            'Mobile App API' => rand(20, 40), // Highest usage
            'Testing API' => rand(5, 15), // Moderate usage
            'Webhook API' => rand(3, 8), // Low usage
            'Analytics API' => rand(2, 6), // Low usage
            'Bulk Booking API' => rand(8, 20), // Moderate to high usage
            default => rand(3, 10), // Default moderate usage
        };
    }

    /**
     * Generate realistic access times based on API type and usage patterns
     */
    private function generateRealisticAccessTime(string $apiTitle, int $sessionIndex): Carbon
    {
        $baseTime = now();
        
        // Different access patterns for different API types
        switch ($apiTitle) {
            case 'Production API':
                // Production APIs accessed throughout business hours
                return $baseTime->subDays(rand(0, 30))
                    ->setHour(rand(8, 18))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                    
            case 'Mobile App API':
                // Mobile APIs have more varied access times
                return $baseTime->subDays(rand(0, 7))
                    ->setHour(rand(6, 23))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                    
            case 'Testing API':
                // Testing APIs mostly used during development hours
                return $baseTime->subDays(rand(0, 14))
                    ->setHour(rand(9, 17))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                    
            case 'Webhook API':
                // Webhooks are set up less frequently
                return $baseTime->subDays(rand(0, 60))
                    ->setHour(rand(8, 20))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                    
            case 'Analytics API':
                // Analytics accessed periodically for reports
                return $baseTime->subDays(rand(0, 45))
                    ->setHour(rand(9, 18))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                    
            case 'Bulk Booking API':
                // Bulk APIs used regularly by corporate clients
                return $baseTime->subDays(rand(0, 21))
                    ->setHour(rand(8, 17))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
                    
            default:
                // Default random access pattern
                return $baseTime->subDays(rand(0, 30))
                    ->setHour(rand(8, 20))
                    ->setMinute(rand(0, 59))
                    ->setSecond(rand(0, 59));
        }
    }
}
