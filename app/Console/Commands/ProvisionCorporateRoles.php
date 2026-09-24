<?php

namespace App\Console\Commands;

use App\Models\Corporate\Corporate;
use App\Services\CorporateRoleStarterService;
use Illuminate\Console\Command;

class ProvisionCorporateRoles extends Command
{
    protected $signature = 'corporate:provision-roles {--corporate= : Provision one corporate UUID only}';
    protected $description = 'Create four company-specific starter roles and migrate legacy assignments';

    public function handle(CorporateRoleStarterService $service): int
    {
        $query = Corporate::query();
        if ($id = $this->option('corporate')) {
            $query->whereKey($id);
        }

        $corporates = 0;
        $roles = 0;
        $assignments = 0;
        $query->orderBy('id')->chunkById(100, function ($items) use ($service, &$corporates, &$roles, &$assignments) {
            foreach ($items as $corporate) {
                $result = $service->provision($corporate);
                $corporates++;
                $roles += $result['roles_created'];
                $assignments += $result['assignments_migrated'];
            }
        });

        $this->table(['Corporates', 'Roles added', 'Assignments moved'], [[$corporates, $roles, $assignments]]);
        return self::SUCCESS;
    }
}
