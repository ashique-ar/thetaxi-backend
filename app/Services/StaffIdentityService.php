<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingActivity;
use App\Models\PhoneCall;
use App\Models\PhoneCallAssignment;
use App\Models\Sales\SalesTask;
use App\Models\Sales\SalesTaskEvent;
use App\Models\Sales\SalesOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Notifications\DatabaseNotification;
use App\Models\Sales\SalesProfile;
use App\Services\Sales\BookingAttributionMutationService;
use App\Services\Sales\SalesCrmService;
use App\Services\Hr\PeopleCoreService;

class StaffIdentityService
{
    public function __construct(
        private readonly UserContextService $contextService,
        private readonly BookingAttributionMutationService $attributionMutations,
        private readonly PeopleCoreService $peopleCore,
        private readonly SalesCrmService $salesCrm,
    ) {}

    public function terminate(Staff $staff, User $actor, string $reason, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($staff, $actor, $reason, $idempotencyKey) {
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

            $salesProfiles = SalesProfile::query()
                ->where('staff_id', $lockedStaff->id)
                ->get();
            $salesProfiles->where('status', 'active')->each(fn (SalesProfile $profile) => $this->attributionMutations->closeLeaverPortfolio(
                    $profile,
                    null,
                    now(),
                    $reason,
                    "staff-termination:{$lockedStaff->id}:{$profile->id}",
                    $actor->id,
                ));
            $releasedCustomerCount = $this->releaseCustomerOwnership($salesProfiles, $actor);
            $releasedInquiryCount = $this->releaseInquiries($lockedStaff, $user, $actor);
            $reassignedOpportunityCounts = $this->reassignSalesOpportunities($lockedStaff, $salesProfiles, $actor);
            $reassignedSalesTaskCounts = $this->reassignSalesTasks($lockedStaff, $salesProfiles, $actor);
            $releasedMissedCallCount = $this->releaseMissedCalls($lockedStaff, $user, $actor);
            $releasedBookingCount = $this->releaseBookings($lockedStaff, $user, $actor);

            $this->contextService->deactivateContext($user, 'staff', null, $actor->id);
            $lockedStaff->paymentMethodChanges()->where('status', 'pending')->update([
                'status' => 'cancelled',
                'review_notes' => 'Cancelled automatically when the Staff context was terminated.',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'updated_user_id' => $actor->id,
            ]);

            $employmentSpell = $this->peopleCore->closeEmployment($lockedStaff, $reason, $actor->id);

            $lockedStaff->forceFill([
                'employment_ended_at' => now(),
                'termination_reason' => $reason,
                'terminated_by' => $actor->id,
                'updated_user_id' => $actor->id,
            ])->save();
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
                'customers_returned_to_queue' => $releasedCustomerCount,
                'inquiries_returned_to_queue' => $releasedInquiryCount,
                'sales_tasks_reassigned' => $reassignedSalesTaskCounts['reassigned'],
                'sales_tasks_needing_manager_review' => $reassignedSalesTaskCounts['needs_manager_review'],
                'sales_opportunities_reassigned' => $reassignedOpportunityCounts['reassigned'],
                'sales_opportunities_needing_manager_review' => $reassignedOpportunityCounts['needs_manager_review'],
                'missed_calls_returned_to_queue' => $releasedMissedCallCount,
                'bookings_returned_to_queue' => $releasedBookingCount,
                'remaining_active_contexts' => $remainingContexts,
                'user_disabled' => false,
            ];

            DB::table('staff_context_termination_events')->insert([
                'id' => (string) Str::uuid(),
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
                    'customers_returned_to_queue' => $releasedCustomerCount,
                    'inquiries_returned_to_queue' => $releasedInquiryCount,
                    'sales_tasks_reassigned' => $reassignedSalesTaskCounts['reassigned'],
                    'sales_tasks_needing_manager_review' => $reassignedSalesTaskCounts['needs_manager_review'],
                    'sales_opportunities_reassigned' => $reassignedOpportunityCounts['reassigned'],
                    'sales_opportunities_needing_manager_review' => $reassignedOpportunityCounts['needs_manager_review'],
                    'missed_calls_returned_to_queue' => $releasedMissedCallCount,
                    'bookings_returned_to_queue' => $releasedBookingCount,
                    'remaining_active_contexts' => $remainingContexts,
                    'user_disabled' => false,
                    'termination_event_idempotency_key' => $idempotencyKey,
                ])
                ->log('staff_context_terminated');

            return $outcome;
        });
    }

    private function reassignSalesOpportunities(Staff $staff, $salesProfiles, User $actor): array
    {
        $profileIds = $salesProfiles->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($profileIds === []) return ['reassigned' => 0, 'needs_manager_review' => 0];

        $opportunities = SalesOpportunity::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $staff->company_id))
            ->whereIn('owner_sales_profile_id', $profileIds)->whereNotIn('stage', ['won', 'lost'])->orderBy('id')->get();
        $managerCache = [];
        $reassigned = 0;
        $needsManagerReview = 0;
        $managerCounts = [];
        foreach ($opportunities as $opportunity) {
            $oldOwnerId = (string) $opportunity->owner_sales_profile_id;
            $manager = $managerCache[$oldOwnerId] ??= $this->activeReportingManager($oldOwnerId, $profileIds);
            if (! $manager) {
                $needsManagerReview++;
                continue;
            }

            $this->salesCrm->transfer(
                $opportunity,
                $manager,
                (int) $opportunity->state_version,
                'Transferred to the active reporting manager after staff termination.',
                "staff-termination:{$staff->id}:opportunity:{$opportunity->id}",
                (string) $actor->id,
            );
            $reassigned++;
            $managerUserId = (string) $manager->staff?->user?->id;
            if ($managerUserId !== '') $managerCounts[$managerUserId] = ($managerCounts[$managerUserId] ?? 0) + 1;
        }

        foreach ($managerCounts as $managerUserId => $count) {
            if ((string) $actor->id === $managerUserId) continue;
            DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\SalesTaskNotice',
                'notifiable_type' => User::class,
                'notifiable_id' => $managerUserId,
                'data' => [
                    'notification_type' => 'sales_opportunities_reassigned',
                    'title' => 'Sales opportunities reassigned',
                    'message' => "{$count} open sales opportunity(s) were assigned to you after a staff change.",
                    'link' => '/sales/opportunities',
                    'useRouter' => true,
                ],
            ]);
        }

        return ['reassigned' => $reassigned, 'needs_manager_review' => $needsManagerReview];
    }

    private function reassignSalesTasks(Staff $staff, $salesProfiles, User $actor): array
    {
        $profileIds = $salesProfiles->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($profileIds === []) return ['reassigned' => 0, 'needs_manager_review' => 0];

        $tasks = SalesTask::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $staff->company_id))
            ->whereIn('owner_sales_profile_id', $profileIds)->whereIn('status', ['open', 'in_progress'])
            ->orderBy('id')->get();
        $managerCache = [];
        $counts = [];
        $needsManagerReview = 0;
        foreach ($tasks as $task) {
            $oldOwnerId = (string) $task->owner_sales_profile_id;
            $manager = $managerCache[$oldOwnerId] ??= $this->activeReportingManager($oldOwnerId, $profileIds);
            $locked = SalesTask::query()->whereKey($task->id)->lockForUpdate()->first();
            if (! $locked || !\App\Services\CompanyIdScope::sameCompanyId((string) ((string) $locked->company_id), (string) ((string) $staff->company_id))
                || (string) $locked->owner_sales_profile_id !== $oldOwnerId
                || ! in_array($locked->status, ['open', 'in_progress'], true)) continue;

            if (! $manager) {
                // Former-owner tasks remain in the existing manager transfer queue.
                $needsManagerReview++;
                continue;
            }

            $version = (int) $locked->state_version;
            $locked->update([
                'owner_sales_profile_id' => $manager->id,
                'state_version' => $version + 1,
                'updated_user_id' => $actor->id,
            ]);
            SalesTaskEvent::create([
                'task_id' => $locked->id,
                'event_type' => 'reassigned',
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'expected_version' => $version,
                'from_owner_sales_profile_id' => $oldOwnerId,
                'to_owner_sales_profile_id' => $manager->id,
                'reason' => 'Reassigned to an active reporting manager after staff termination.',
                'idempotency_key' => "staff-termination:{$staff->id}:task:{$locked->id}",
                'actor_user_id' => $actor->id,
                'occurred_at' => now(),
            ]);
            $managerUserId = (string) $manager->staff?->user?->id;
            if ($managerUserId !== '') $counts[$managerUserId] = ($counts[$managerUserId] ?? 0) + 1;
        }

        foreach ($counts as $managerUserId => $count) {
            if ((string) $actor->id === $managerUserId) continue;
            DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\SalesTaskNotice',
                'notifiable_type' => User::class,
                'notifiable_id' => $managerUserId,
                'data' => [
                    'notification_type' => 'sales_task_reassigned',
                    'title' => 'Follow-up tasks reassigned',
                    'message' => "{$count} open follow-up task(s) were assigned to you after a staff change.",
                    'link' => '/sales/work',
                    'useRouter' => true,
                ],
            ]);
        }

        return ['reassigned' => array_sum($counts), 'needs_manager_review' => $needsManagerReview];
    }

    private function activeReportingManager(string $memberProfileId, array $leaverProfileIds): ?SalesProfile
    {
        $frontier = [$memberProfileId];
        $visited = $frontier;
        $now = now();
        while ($frontier !== []) {
            $rows = DB::table('sales_reporting_assignments')
                ->whereIn('member_sales_profile_id', $frontier)->whereNull('deleted_at')
                ->where('effective_from', '<=', $now)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $now))
                ->orderByDesc('effective_from')->get(['manager_sales_profile_id']);
            $frontier = [];
            foreach ($rows as $row) {
                $managerId = (string) $row->manager_sales_profile_id;
                if (in_array($managerId, $visited, true)) continue;
                $visited[] = $managerId;
                $manager = SalesProfile::query()->whereKey($managerId)
                    ->activeAt($now)->configured()
                    ->whereHas('staff', fn ($staff) => $staff->whereNull('deleted_at')
                        ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', $now)))
                    ->with('staff.user')->first();
                $user = $manager?->staff?->user;
                $eligible = $manager && $user && $user->is_active && ! $user->trashed()
                    && ! $manager->staff->trashed()
                    && DB::table('user_contexts')->where('user_id', $user->id)->where('context_type', 'staff')
                        ->where('context_id', $manager->staff_id)->where('is_active', true)->whereNull('deleted_at')->exists();
                if ($eligible && ! in_array($managerId, $leaverProfileIds, true)) return $manager;
                $frontier[] = $managerId;
            }
        }
        return null;
    }

    private function releaseMissedCalls(Staff $staff, User $departingUser, User $actor): int
    {
        $calls = PhoneCall::query()->where('assigned_user_id', $departingUser->id)->whereIn('call_status', ['Missed', 'Notanswered'])
            ->orderBy('id')->get(['id']);
        $released = 0;
        foreach ($calls as $call) {
            $locked = PhoneCall::query()->whereKey($call->id)->where('assigned_user_id', $departingUser->id)
                ->whereIn('call_status', ['Missed', 'Notanswered'])->lockForUpdate()->first();
            if (! $locked) continue;
            $locked->update(['assigned_user_id' => null, 'updated_user_id' => $actor->id]);
            PhoneCallAssignment::create([
                'phone_call_id' => $locked->id,
                'from_user_id' => $departingUser->id,
                'to_user_id' => null,
                'actor_user_id' => $actor->id,
                'reason' => 'Missed call returned to the follow-up queue after staff termination.',
                'event_at' => now(),
            ]);
            $released++;
        }
        return $released;
    }

    private function releaseCustomerOwnership($salesProfiles, User $actor): int
    {
        $profileIds = $salesProfiles->pluck('id');
        if ($profileIds->isEmpty()) {
            return 0;
        }

        $customerIds = DB::table('customer_relationship_owner_assignments')
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $salesProfiles->first()->company_id))
            ->whereIn('owner_sales_profile_id', $profileIds)
            ->whereNull('ended_at')
            ->distinct()->pluck('customer_id');
        $released = 0;

        foreach ($customerIds as $customerId) {
            DB::table('customers')->where('id', $customerId)->lockForUpdate()->first();
            $current = DB::table('customer_relationship_owner_assignments')
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $salesProfiles->first()->company_id))
                ->where('customer_id', $customerId)
                ->whereIn('owner_sales_profile_id', $profileIds)
                ->whereNull('ended_at')->lockForUpdate()->first();
            if (! $current) {
                continue;
            }

            $now = now();
            DB::table('customer_relationship_owner_assignments')->where('id', $current->id)->update([
                'ended_at' => $now,
                'ended_by_user_id' => $actor->id,
                'updated_at' => $now,
            ]);
            DB::table('customer_relationship_owner_assignments')->insert([
                'id' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'company_id' => $current->company_id,
                'owner_sales_profile_id' => null,
                'assigned_by_user_id' => $actor->id,
                'reason' => 'Returned to the customer assignment queue after staff termination.',
                'effective_from' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $released++;
        }

        return $released;
    }

    private function releaseInquiries(Staff $staff, User $departingUser, User $actor): int
    {
        $released = 0;
        DB::table('inquiries')->where('assigned_to', $departingUser->id)->whereIn('status', ['open', 'in_progress'])
            ->orderBy('id')->chunkById(100, function ($inquiries) use ($staff, $departingUser, $actor, &$released): void {
                foreach ($inquiries as $inquiry) {
                    $locked = DB::table('inquiries')->where('id', $inquiry->id)->lockForUpdate()->first();
                    if (! $locked || $locked->assigned_to !== $departingUser->id) {
                        continue;
                    }

                    $now = now();
                    DB::table('inquiries')->where('id', $locked->id)->update([
                        'assigned_to' => null,
                        'updated_user_id' => $actor->id,
                        'updated_at' => $now,
                    ]);
                    DB::table('inquiry_assignment_events')->insert([
                        'id' => (string) Str::uuid(),
                        'inquiry_id' => $locked->id,
                        'from_user_id' => $departingUser->id,
                        'to_user_id' => null,
                        'actor_user_id' => $actor->id,
                        'event_type' => 'returned_to_queue',
                        'reason' => 'Returned to the inquiry queue after staff termination.',
                        'idempotency_key' => "staff-termination:{$staff->id}:inquiry:{$locked->id}",
                        'occurred_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $released++;
                }
            }, 'id');

        return $released;
    }

    private function releaseBookings(Staff $staff, User $departingUser, User $actor): int
    {
        $released = 0;
        $query = Booking::query()->where('responsible_user_id', $departingUser->id)
            ->whereNotIn('status', ['cancelled', 'completed']);
        CompanyBookingScope::constrain($query, 'bookings', $staff->company_id);
        $query->orderBy('id')->chunkById(100, function ($bookings) use ($staff, $departingUser, $actor, &$released): void {
            foreach ($bookings as $booking) {
                $lockedQuery = Booking::query()->whereKey($booking->id)
                    ->where('responsible_user_id', $departingUser->id)
                    ->whereNotIn('status', ['cancelled', 'completed']);
                CompanyBookingScope::constrain($lockedQuery, 'bookings', $staff->company_id);
                $locked = $lockedQuery->lockForUpdate()->first();
                if (! $locked) {
                    continue;
                }

                $now = now();
                $locked->forceFill(['responsible_user_id' => null, 'updated_user_id' => $actor->id])->save();
                BookingActivity::create([
                    'booking_id' => $locked->id,
                    'event_key' => 'booking_responsible_user_changed',
                    'channel' => 'system',
                    'result_status' => 'completed',
                    'source' => 'system',
                    'title' => 'Booking returned to assignment queue',
                    'detail' => 'Booking responsibility was cleared after staff termination.',
                    'idempotency_key' => "staff-termination:{$staff->id}:booking:{$locked->id}",
                    'meta' => ['from_user_id' => $departingUser->id, 'to_user_id' => null, 'actor_user_id' => (string) $actor->id],
                    'event_at' => $now,
                ]);
                $bookingId = (string) $locked->id;
                DB::afterCommit(static function () use ($bookingId): void {
                    $booking = Booking::query()->find($bookingId);
                    if ($booking) app(BookingResponsibilityService::class)->notifyUnassignedBooking($booking);
                });
                $released++;
            }
        }, 'id');

        return $released;
    }
}
