<?php

namespace Database\Seeders;

use App\Models\Service\ServiceType;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * This seeder runs all the major data seeders in the correct order
     * to ensure proper relationships and dependencies.
     */
    public function run(): void
    {
        $this->command->info('🌱 Starting Master Data Seeding...');

        // 1. Pricing and Service Configuration
        $this->command->info('📊 Seeding pricing and service data...');
        $this->call(ComprehensivePricingSeeder::class);

        // 2. Vehicle Addons
        $this->command->info('🚗 Seeding vehicle addons...');
        $this->call(VehicleAddonSeeder::class);

        // 3. Vehicle Addon Dependencies (requires addons to exist first)
        $this->command->info('🔗 Seeding vehicle addon dependencies...');
        $this->call(VehicleAddonDependencySeeder::class);

        // 4. Agents (requires users)
        $this->command->info('👥 Seeding agents...');
        $this->call(AgentSeeder::class);

        // 5. Agent APIs (requires agents)
        $this->command->info('🔑 Seeding agent APIs...');
        $this->call(AgentApiSeeder::class);

        // 6. Agent API Sessions (requires agent APIs)
        $this->command->info('📱 Seeding agent API sessions...');
        $this->call(AgentApiSessionSeeder::class);

        // 7. Agent Commissions (requires agents and optionally bookings)
        $this->command->info('💰 Seeding agent commissions...');
        $this->call(AgentCommissionSeeder::class);

        $this->command->info('✅ Master Data Seeding completed successfully!');
        $this->command->newLine();
        $this->printSeedingSummary();
    }

    /**
     * Print a summary of what was seeded
     */
    private function printSeedingSummary(): void
    {
        $this->command->info('📋 Seeding Summary:');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        // Service Types and Pricing
        $serviceTypesCount = ServiceType::count();
        $this->command->info("🔹 Service Types: {$serviceTypesCount}");
        
        $slabDefinitionsCount = \App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition::count();
        $this->command->info("🔹 Pricing Slab Definitions: {$slabDefinitionsCount}");
        
        $commonRatesCount = \App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition::count();
        $this->command->info("🔹 Common Rate Definitions: {$commonRatesCount}");
        
        // Vehicle Addons
        $addonsCount = \App\Models\Vehicle\VehicleAddon::count();
        $this->command->info("🔹 Vehicle Addons: {$addonsCount}");
        
        $dependenciesCount = \App\Models\Vehicle\VehicleAddonDependency::count();
        $this->command->info("🔹 Addon Dependencies: {$dependenciesCount}");
        
        // Agents
        $agentsCount = \App\Models\Agent\Agent::count();
        $this->command->info("🔹 Agents: {$agentsCount}");
        
        $agentApisCount = \App\Models\Agent\AgentApi::count();
        $this->command->info("🔹 Agent APIs: {$agentApisCount}");
        
        $apiSessionsCount = \App\Models\Agent\AgentApiSession::count();
        $this->command->info("🔹 API Sessions: {$apiSessionsCount}");
        
        $commissionsCount = \App\Models\Agent\AgentCommission::count();
        $this->command->info("🔹 Agent Commissions: {$commissionsCount}");
        
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->newLine();
        
        $this->command->info('💡 Quick Start Tips:');
        $this->command->info('• Run "php artisan migrate:fresh --seed --seeder=MasterDataSeeder" to reset and seed all data');
        $this->command->info('• Agent login credentials: email from agent data, password: "password123"');
        $this->command->info('• API keys are generated for each agent and can be found in agent_apis table');
        $this->command->info('• Vehicle addons include dependencies for smart recommendation logic');
    }
}
