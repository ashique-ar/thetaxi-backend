<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class StaffRoleContextSeeder extends Seeder
{
    private const ROLES = [
        'accountant', 'admin-mkt', 'data-entry', 'driver-coordinator', 'fleet-manager',
        'hr-integration-adapter', 'hr-manager', 'hr-notification-adapter', 'hr-officer',
        'hr-safety-integration-adapter', 'management', 'rep-marketing', 'sales-manager',
        'salesperson', 'sub-admin',
    ];

    public function run(): void
    {
        $contexts = app(UserContextService::class);

        Role::query()->whereIn('name', self::ROLES)->each(function (Role $role) use ($contexts): void {
            $userIds = DB::table('model_has_roles')
                ->where('role_id', $role->id)
                ->where('model_type', User::class)
                ->pluck('model_id')
                ->unique();

            User::query()->whereIn('id', $userIds)->where('is_active', true)->each(function (User $user) use ($contexts, $role): void {
                $formerStaff = Staff::withTrashed()->where('user_id', $user->id)
                    ->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhereNotNull('employment_ended_at'))
                    ->exists();

                if (!$formerStaff) {
                    $contexts->syncContextsForAssignedRoles($user, [$role]);
                }
            });
        });
    }
}
