<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleContextMappingSeeder extends Seeder
{
    public function run(): void
    {
        $contextService = app(UserContextService::class);

        Role::query()->where('guard_name', config('permissions.canonical_guard', 'api'))
            ->orderBy('id')->each(function (Role $role) use ($contextService): void {
                $contextTypes = $role->context_types;
                if (is_string($contextTypes)) {
                    $contextTypes = json_decode($contextTypes, true);
                }

                if (!is_array($contextTypes)) {
                    $contextTypes = DB::table('user_context_roles')
                        ->join('user_contexts', 'user_contexts.id', '=', 'user_context_roles.user_context_id')
                        ->where('user_context_roles.role_id', $role->id)
                        ->where('user_contexts.is_active', true)
                        ->whereNull('user_contexts.deleted_at')
                        ->distinct()->pluck('user_contexts.context_type')->all();
                }

                $mappedContextTypes = collect($contextTypes)
                    ->map(fn ($type) => $type === 'internal' ? 'staff' : $type)
                    ->filter(fn ($type) => in_array($type, ['customer', 'vehicle_owner', 'staff', 'agent', 'driver', 'corporate'], true))
                    ->unique()->values()->all();

                if (in_array($role->name, ['admin', 'sub-admin', 'super-admin'], true)) {
                    $mappedContextTypes = ['internal', 'staff'];
                }

                if ($mappedContextTypes !== $contextTypes) {
                    $role->update(['context_types' => json_encode($mappedContextTypes)]);
                }

                if ($mappedContextTypes === []) {
                    return;
                }

                $userIds = DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('model_type', User::class)
                    ->pluck('model_id')
                    ->merge(DB::table('user_direct_role_grants')->where('role_id', $role->id)->pluck('user_id'))
                    ->merge(DB::table('user_context_roles')
                        ->join('user_contexts', 'user_contexts.id', '=', 'user_context_roles.user_context_id')
                        ->where('user_context_roles.role_id', $role->id)
                        ->where('user_contexts.is_active', true)
                        ->whereNull('user_contexts.deleted_at')
                        ->pluck('user_contexts.user_id'))
                    ->unique();

                User::query()->whereIn('id', $userIds)->where('is_active', true)->each(function (User $user) use ($role, $mappedContextTypes, $contextService): void {
                    if (in_array('staff', $mappedContextTypes, true) && Staff::withTrashed()->where('user_id', $user->id)
                        ->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhereNotNull('employment_ended_at'))
                        ->exists()) {
                        return;
                    }

                    $contextService->syncContextsForAssignedRoles($user, [$role]);

                    if (in_array($role->name, ['admin', 'sub-admin', 'super-admin'], true)) {
                        $contextService->switchContext($user, 'customer', ['roles' => ['customer']]);
                    }
                });
            });
    }
}
