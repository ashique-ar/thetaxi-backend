<?php

namespace App\Console\Commands;

use App\Models\Service\ServiceType;
use Illuminate\Console\Command;

class SetMinimumKmForServiceTypes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'service-types:set-minimum-km 
                            {--km=20 : The minimum KM value to set (default: 20)}
                            {--service= : Specific service type code to update (optional)}
                            {--force : Update even if minimum_km is already set}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set minimum KM charge for service types. Default is 20 KM for all service types.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minimumKm = (float) $this->option('km');
        $specificService = $this->option('service');
        $force = $this->option('force');

        if ($minimumKm <= 0) {
            $this->error('Minimum KM must be greater than 0.');
            return Command::FAILURE;
        }

        $query = ServiceType::query();

        if ($specificService) {
            $query->where('code', $specificService);
        }

        if (!$force) {
            // Only update service types where minimum_km is null
            $query->whereNull('minimum_km');
        }

        $serviceTypes = $query->get();

        if ($serviceTypes->isEmpty()) {
            if ($specificService) {
                $this->warn("No service type found with code: {$specificService}");
            } else {
                $this->info('All service types already have minimum_km set. Use --force to override.');
            }
            return Command::SUCCESS;
        }

        $this->info("Setting minimum KM to {$minimumKm} for " . $serviceTypes->count() . " service type(s)...");

        $updated = 0;
        foreach ($serviceTypes as $serviceType) {
            $oldValue = $serviceType->minimum_km;
            $serviceType->minimum_km = $minimumKm;
            $serviceType->save();

            $this->line("  ✓ {$serviceType->name} ({$serviceType->code}): " . 
                ($oldValue ? "{$oldValue} → {$minimumKm}" : "null → {$minimumKm}") . " km");
            $updated++;
        }

        $this->newLine();
        $this->info("Successfully updated {$updated} service type(s) with minimum_km = {$minimumKm} km.");

        return Command::SUCCESS;
    }
}
