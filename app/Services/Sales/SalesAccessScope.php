<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesProfile;
use App\Models\User;
use App\Services\PermissionEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SalesAccessScope
{
    public function __construct(private readonly PermissionEvaluator $permissions) {}

    public function activeProfile(User $user): ?SalesProfile
    {
        $profiles = $this->actorProfiles($user)->take(2);

        return $profiles->count() === 1 ? $profiles->first() : null;
    }

    public function actorProfiles(User $user)
    {
        $contextType = request()->header('X-Active-Context-Type');
        $contextId = request()->header('X-Active-Context-Id');
        if ($contextType === 'staff' && (! $contextId || ! \Illuminate\Support\Str::isUuid($contextId))) {
            return collect();
        }
        if ($contextType !== null && ! in_array($contextType, ['staff', 'internal'], true)) {
            return collect();
        }

        return SalesProfile::query()
            ->whereHas('staff', function (Builder $staff) use ($user, $contextType, $contextId): void {
                $staff->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->where(fn (Builder $employment) => $employment
                        ->whereNull('employment_ended_at')
                        ->orWhere('employment_ended_at', '>', now()))
                    ->when($contextType === 'staff', fn (Builder $staff) => $staff->whereExists(
                        fn ($context) => $context->selectRaw('1')->from('user_contexts')
                            ->whereColumn('user_contexts.context_id', 'staff.id')
                            ->where('user_contexts.id', $contextId)
                            ->where('user_contexts.user_id', $user->id)
                            ->where('user_contexts.context_type', 'staff')
                            ->where('user_contexts.is_active', true)->whereNull('user_contexts.deleted_at'),
                    ));
            })
            ->activeAt(now())
            ->configured()
            ->orderBy('id')
            ->get();
    }

    public function profileIds(
        User $user,
        string $allPermission,
        string $teamPermission,
    ): ?array
    {
        if ($this->hasPermission($user, $allPermission)) {
            return null;
        }

        $profiles = $this->actorProfiles($user);
        if ($profiles->isEmpty()) {
            return [];
        }

        $ids = $profiles->pluck('id')->all();
        if ($this->hasPermission($user, $teamPermission)) {
            $assignments = DB::table('sales_reporting_assignments')
                ->whereNull('deleted_at')
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->whereIn('manager_sales_profile_id', $ids)
                ->get(['manager_sales_profile_id', 'member_sales_profile_id']);

            $children = [];
            foreach ($assignments as $assignment) {
                $children[$assignment->manager_sales_profile_id][] = $assignment->member_sales_profile_id;
            }

            $pending = $ids;
            while ($pending !== []) {
                $managerId = array_pop($pending);
                foreach ($children[$managerId] ?? [] as $memberId) {
                    if (in_array($memberId, $ids, true)) {
                        continue;
                    }
                    $ids[] = $memberId;
                    $pending[] = $memberId;
                }
            }

            $ids = SalesProfile::query()
                ->whereIn('id', $ids)
                ->activeAt(now())
                ->configured()
                ->pluck('id')
                ->all();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolve an optional action scope without turning a view response into an
     * authorization error. A null result means all Profiles are authorized;
     * an empty array means the actor has no safe, unambiguous action scope.
     */
    public function authorizedProfileIds(
        User $user,
        string $basePermission,
        string $allPermission,
        string $teamPermission,
    ): ?array {
        if (! $this->hasPermission($user, $basePermission)) {
            return [];
        }
        if ($this->hasPermission($user, $allPermission)) {
            return null;
        }
        if ($this->actorProfiles($user)->count() !== 1) {
            return [];
        }

        return $this->profileIds($user, $allPermission, $teamPermission);
    }

    public function scopeProfiles(
        Builder $query,
        User $user,
        string $allPermission,
        string $teamPermission,
    ): Builder {
        $ids = $this->profileIds($user, $allPermission, $teamPermission);

        return $ids === null ? $query : $query->whereIn($query->getModel()->qualifyColumn('id'), $ids);
    }

    public function assertProfile(
        User $user,
        SalesProfile $profile,
        string $allPermission,
        string $teamPermission,
    ): void
    {
        $ids = $this->profileIds($user, $allPermission, $teamPermission);
        if ($ids !== null && ! in_array($profile->id, $ids, true)) {
            $this->deny('SUBJECT_SCOPE_DENIED', 'The Sales Profile is outside your permitted scope.');
        }
    }

    public function assertAccess(User $user, string $allPermission): void
    {
        if ($this->hasPermission($user, $allPermission)) {
            return;
        }

        if (! $this->actorProfiles($user)->isNotEmpty()) {
            $this->deny('SUBJECT_SCOPE_DENIED', 'You do not have access to Sales records.');
        }
    }

    public function scopeType(User $user, string $allPermission, string $teamPermission): string
    {
        if ($this->hasPermission($user, $allPermission)) {
            return 'all';
        }

        return $this->hasPermission($user, $teamPermission) ? 'team' : 'self';
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->permissions->userHasAnyForInternalContext($user, [$permission]);
    }

    private function deny(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ], Response::HTTP_FORBIDDEN));
    }
}
