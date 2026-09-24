<?php

namespace App\Console\Commands;

use App\Models\Corporate\Corporate;
use App\Services\CorporateStaffTransportStarterService;
use Illuminate\Console\Command;

class ProvisionCorporateStaffTransport extends Command
{
    protected $signature = 'corporate:provision-staff-transport {--corporate= : Provision one corporate UUID}';
    protected $description = 'Add missing editable Staff Transport starter data to existing corporates';

    public function handle(CorporateStaffTransportStarterService $starter): int
    {
        $query = Corporate::query()->orderBy('name');
        if ($corporateId = $this->option('corporate')) {
            $query->whereKey($corporateId);
        }

        $processed = $programs = $shifts = $routes = 0;
        $query->chunkById(100, function ($corporates) use ($starter, &$processed, &$programs, &$shifts, &$routes) {
            foreach ($corporates as $corporate) {
                $result = $starter->provision($corporate);
                $processed++;
                $programs += (int) $result['program_created'];
                $shifts += $result['shifts_created'];
                $routes += $result['routes_created'];
            }
        });

        $this->table(['Corporates', 'Programs added', 'Shifts added', 'Route templates added'], [[$processed, $programs, $shifts, $routes]]);
        return self::SUCCESS;
    }
}
