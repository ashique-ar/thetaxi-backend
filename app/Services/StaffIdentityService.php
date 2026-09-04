<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
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

    public function terminate(Staff $staff, User $actor, string $reason, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($staff, $actor, $reason, $idempotencyKey) {
            abort_unless(DB::table('companies')->where('id',$staff->company_id)->lockForUpdate()->first(),404,'Staff legal entity was not found.');
            $lockedStaff = Staff::withTrashed()->lockForUpdate()->findOrFail($staff->id);
            $requestChecksum = hash('sha256', json_encode([
                'staff_id' => (string) $lockedStaff->id,
                'reason' => trim($reason),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $existing = DB::table('staff_context_termination_events')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                abort_unless(
                    (string) $existing->staff_id === (string) $lockedStaff->id
                    && hash_equals((string) $existing->request_checksum, $requestChecksum),
                    409,
                    'This termination request identity was already used with different facts.'
                );

                return json_decode((string) $existing->outcome_snapshot, true, 512, JSON_THROW_ON_ERROR);
            }
            abort_if($lockedStaff->trashed() || $lockedStaff->employment_ended_at, 409, 'Staff employment has already ended.');
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

            $employmentSpell = $this->peopleCore->closeEmployment($lockedStaff, $reason, $actor->id);

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
            $outcome = [
                'staff_id' => $lockedStaff->id,
                'user_id' => $user->id,
                'remaining_active_contexts' => $remainingContexts,
                'user_disabled' => false,
            ];

            DB::table('staff_context_termination_events')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $lockedStaff->company_id,
                'staff_id' => $lockedStaff->id,
                'user_id' => $user->id,
                'employment_spell_id' => $employmentSpell?->id,
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'request_checksum' => $requestChecksum,
                'encrypted_reason' => Crypt::encryptString(trim($reason)),
                'outcome_snapshot' => json_encode($outcome, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'terminated_at' => $lockedStaff->employment_ended_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            activity('staff-identity')
                ->causedBy($actor)
                ->performedOn($lockedStaff)
                ->withProperties([
                    'user_id' => $user->id,
                    'remaining_active_contexts' => $remainingContexts,
                    'user_disabled' => false,
                    'termination_event_idempotency_key' => $idempotencyKey,
                ])
                ->log('staff_context_terminated');

            return $outcome;
        });
    }
}
