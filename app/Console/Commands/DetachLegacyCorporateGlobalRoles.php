<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Console\Command;

class DetachLegacyCorporateGlobalRoles extends Command
{
    protected $signature = 'corporate:detach-global-roles {--apply : Remove direct user role assignments after review}';

    protected $description = 'Audit or detach legacy direct corporate roles while retaining user context roles';

    private const DEFAULT_ROLES = [
        'Corporate_Master_Admin', 'Transport_Coordinator', 'Approval_Manager', 'Corporate_Employee',
    ];

    public function handle(): int
    {
        $users = User::query()->whereHas('contexts', fn ($query) => $query->where('context_type', 'corporate'))
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::DEFAULT_ROLES)
                ->orWhere('name', 'like', 'Corporate_%'));

        $count = (clone $users)->count();
        if (! $this->option('apply')) {
            $this->info("{$count} users have global corporate roles. No records changed. Re-run with --apply after review to detach verified direct sources.");
            return self::SUCCESS;
        }

        $removed = $retained = $unverified = 0;
        $contextRoles = app(UserContextService::class);
        $users->with('roles')->chunkById(100, function ($batch) use (&$removed, &$retained, &$unverified, $contextRoles): void {
            foreach ($batch as $user) {
                foreach ($user->roles as $role) {
                    if (!in_array($role->name, self::DEFAULT_ROLES, true)
                        && !str_starts_with($role->name, 'Corporate_')) {
                        continue;
                    }

                    $result = $contextRoles->detachDirectCorporateRole($user, (int) $role->id);
                    if (in_array($result, ['detached', 'retained_direct'], true)) {
                        $removed++;
                    }
                    if (in_array($result, ['retained', 'retained_direct'], true)) {
                        $retained++;
                    }
                    if ($result === 'unverified') {
                        $unverified++;
                    }
                }
            }
        });

        $this->info("Removed {$removed} direct sources; retained {$retained} roles sourced by active contexts; left {$unverified} with unverified origins for review.");
        return self::SUCCESS;
    }
}
