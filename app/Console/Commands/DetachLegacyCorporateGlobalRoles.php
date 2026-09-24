<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
            $this->info("{$count} users have direct corporate roles. No records changed. Re-run with --apply to detach them from the user; corporate context roles remain.");
            return self::SUCCESS;
        }

        $removed = 0;
        DB::transaction(function () use ($users, &$removed): void {
            $users->with('roles')->chunkById(100, function ($batch) use (&$removed): void {
                foreach ($batch as $user) {
                    foreach ($user->roles as $role) {
                        if (in_array($role->name, self::DEFAULT_ROLES, true)
                            || str_starts_with($role->name, 'Corporate_')) {
                            $user->removeRole($role);
                            $removed++;
                        }
                    }
                }
            });
        });

        $this->info("Detached {$removed} direct corporate role assignments. Corporate context roles were not changed.");
        return self::SUCCESS;
    }
}
