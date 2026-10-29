<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\User;
use App\Services\UserContextService;
use App\Services\UserService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class StaffOperationalAccessSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->where('name', 'staff-operations')->where('guard_name', 'api')->firstOrFail();
        $contexts = app(UserContextService::class);
        $userService = app(UserService::class);

        Staff::query()
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->with('user.roles')
            ->chunkById(100, function ($staffMembers) use ($role, $contexts, $userService): void {
                foreach ($staffMembers as $staff) {
                    $user = $staff->user;
                    if (!$user || $user->roles->contains(fn ($assignedRole) => in_array($assignedRole->name, ['admin', 'super-admin'], true))) {
                        continue;
                    }

                    $user->assignRole($role);
                    $userService->grantDirectRoleGrants($user, collect([$role]));
                    $contexts->syncContextsForAssignedRoles($user, [$role]);
                }
            });
    }
}
