<?php

namespace Database\Seeders;

use App\Models\Service\ServiceType;
use App\Services\ServiceTypeCloneService;
use Illuminate\Database\Seeder;

class ClonePublicServiceTypesToPortalSeeder extends Seeder
{
    public function run(): void
    {
        $cloneService = app(ServiceTypeCloneService::class);

        $publicServiceTypes = ServiceType::query()
            ->publicContext()
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        if ($publicServiceTypes->isEmpty()) {
            $this->command?->warn('No public-context service types found to clone into portal context.');
            return;
        }

        $clonedCount = 0;
        $skippedCount = 0;

        foreach ($publicServiceTypes as $serviceType) {
            $existingPortalClone = ServiceType::query()
                ->portalContext()
                ->where(function ($query) use ($serviceType) {
                    $query->where('parent_service_type_id', $serviceType->id)
                        ->orWhere('code', $serviceType->code);
                })
                ->first();

            if ($existingPortalClone) {
                $skippedCount++;
                $this->command?->line(sprintf(
                    'Skipping "%s" because a portal-context copy already exists (%s).',
                    $serviceType->name,
                    $existingPortalClone->code
                ));
                continue;
            }

            $cloneService->clone($serviceType, [
                'code' => $serviceType->code,
                'name' => $serviceType->name,
                'slug' => $serviceType->slug,
                'context' => 'portal',
                'owner_type' => '',
                'owner_id' => '',
            ]);

            $clonedCount++;
            $this->command?->info(sprintf(
                'Cloned public service type "%s" into portal context.',
                $serviceType->name
            ));
        }

        $this->command?->info(sprintf(
            'Portal service type cloning complete. Cloned: %d, skipped: %d.',
            $clonedCount,
            $skippedCount
        ));
    }
}
