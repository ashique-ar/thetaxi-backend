<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Sales\SalesProfile;
use App\Services\Sales\BookingAttributionMutationService;
use App\Services\Hr\PeopleCoreService;

class StaffIdentityService
{
    public function __construct(
        private readonly UserContextService $contextService,
        private readonly BookingAttributionMutationService $attributionMutations,
        private readonly PeopleCoreService $peopleCore,
    ) {}

    public function terminate(Staff $staff, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($staff, $actor, $reason) {
            abort_unless(DB::table('companies')->where('id',$staff->company_id)->lockForUpdate()->first(),404,'Staff legal entity was not found.');
            $lockedStaff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $user = User::query()->lockForUpdate()->findOrFail($lockedStaff->user_id);

            SalesProfile::query()
                ->where('staff_id', $lockedStaff->id)
                ->where('status', 'active')
                ->get()
                ->each(fn (SalesProfile $profile) => $this->attributionMutations->closeLeaverPortfolio(
                    $profile,
                    null,
                    now(),
                    $reason,
                    "staff-termination:{$lockedStaff->id}:{$profile->id}",
                    $actor->id,
                ));

            $this->contextService->deactivateContext($user, 'staff');
            $lockedStaff->paymentMethodChanges()->where('status', 'pending')->update([
                'status' => 'cancelled',
                'review_notes' => 'Cancelled automatically when the Staff context was terminated.',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'updated_user_id' => $actor->id,
            ]);

            $this->peopleCore->closeEmployment($lockedStaff, $reason, $actor->id);

            $lockedStaff->update([
                'employment_ended_at' => now(),
                'termination_reason' => $reason,
                'terminated_by' => $actor->id,
                'updated_user_id' => $actor->id,
            ]);
            $lockedStaff->delete();

            // Passport tokens are not context-scoped. Revoke existing tokens so
            // none retain internal permissions; keep the User active so another
            // authorized context can be selected after re-authentication.
            $user->revokeAllTokens();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            $remainingContexts = $user->contexts()->where('is_active', true)->count();

            activity('staff-identity')
                ->causedBy($actor)
                ->performedOn($lockedStaff)
                ->withProperties([
                    'user_id' => $user->id,
                    'remaining_active_contexts' => $remainingContexts,
                    'user_disabled' => false,
                ])
                ->log('staff_context_terminated');

            return [
                'staff_id' => $lockedStaff->id,
                'user_id' => $user->id,
                'remaining_active_contexts' => $remainingContexts,
                'user_disabled' => false,
            ];
        });
    }
}
