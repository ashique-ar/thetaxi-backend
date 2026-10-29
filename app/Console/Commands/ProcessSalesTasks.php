<?php

namespace App\Console\Commands;

use App\Models\Booking\Booking;
use App\Models\Sales\SalesPolicySetting;
use App\Models\Sales\SalesReportingAssignment;
use App\Models\Sales\SalesTask;
use App\Services\Sales\SalesCrmService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcessSalesTasks extends Command
{
    protected $signature = 'sales:process-tasks {--dry-run}';
    protected $description = 'Dispatch due Sales task reminders and manager escalations';

    public function handle(): int
    {
        if (! Schema::hasTable('sales_tasks') || ! Schema::hasTable('sales_company_feature_settings')) return self::SUCCESS;
        $dryRun = (bool) $this->option('dry-run'); $count = 0; $nowUtc = now('UTC');
        SalesTask::query()->whereIn('status', ['open', 'in_progress'])
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrainIn($_companyIdScope, 'company_id', DB::table('sales_company_feature_settings')->select('company_id')
                ->where('feature_key', 'crm')->where('status', 'approved')->where('enabled', true)))
            ->where(fn ($q) => $q->where(fn ($r) => $r->whereNull('reminder_dispatched_at')->whereNotNull('remind_at')->where('remind_at', '<=', $nowUtc))
                ->orWhere(fn ($r) => $r->whereNull('escalated_at')->whereNotNull('escalate_at')->where('escalate_at', '<=', $nowUtc)))
            ->chunkById(200, function ($tasks) use ($dryRun, &$count, $nowUtc): void {
                foreach ($tasks as $task) {
                    $task->load('owner');
                    $ownerUser = $task->owner?->staff?->user;
                    if (! $task->reminder_dispatched_at && $task->remind_at?->lte($nowUtc) && $ownerUser) {
                        $count++; if (! $dryRun) { $this->notify($ownerUser->id, $task, 'sales_task_reminder'); $task->update(['reminder_dispatched_at' => $nowUtc]); }
                    }
                    if (! $task->escalated_at && $task->escalate_at?->lte($nowUtc)) {
                        $managerProfile = SalesReportingAssignment::query()->where('member_sales_profile_id', $task->owner_sales_profile_id)
                            ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                            ->with('manager.staff.user')->first()?->manager;
                        if ($managerProfile?->staff?->user) {
                            $count++; if (! $dryRun) { $this->notify($managerProfile->staff->user->id, $task, 'sales_task_escalation'); $task->update(['escalated_at' => $nowUtc]); }
                        }
                    }
                }
            });
        $followUpCount = $this->queueMissingRepeatBookingFollowups($dryRun);
        $this->info("Processed {$count} Sales task notification(s) and {$followUpCount} repeat-customer follow-up task(s)." . ($dryRun ? ' (dry run)' : ''));
        return self::SUCCESS;
    }

    private function queueMissingRepeatBookingFollowups(bool $dryRun): int
    {
        if (! Schema::hasTable('sales_policy_settings') || ! Schema::hasTable('customer_relationship_owner_assignments')
            || ! Schema::hasColumn('sales_policy_settings', 'repeat_booking_followup_enabled')
            || ! Schema::hasColumn('sales_tasks', 'creation_idempotency_key')) return 0;

        $count = 0;
        foreach (SalesPolicySetting::query()->where('policy_kind', 'repeat_booking_followup')
            ->where('status', 'approved')->where('repeat_booking_followup_enabled', true)
            ->whereNotNull('repeat_booking_followup_days')->whereNotNull('approved_at')
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrainIn($_companyIdScope, 'company_id', DB::table('sales_company_feature_settings')->select('company_id')
                ->where('feature_key', 'crm')->where('status', 'approved')->where('enabled', true)))
            ->get() as $policy) {
            Booking::query()->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $policy->company_id))->where('status', 'completed')
                ->whereNotNull('customer_id')->whereNotNull('completed_at')->where('completed_at', '>=', $policy->approved_at)
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')->from('customer_relationship_owner_assignments as current_owner')
                        ->whereColumn('current_owner.customer_id', 'bookings.customer_id')
                        ->whereColumn('current_owner.company_id', 'bookings.company_id')
                        ->whereNotNull('current_owner.owner_sales_profile_id')->whereNull('current_owner.ended_at')
                        ->where('current_owner.effective_from', '<=', now());
                })
                ->where(function ($eligible) use ($policy): void {
                    $eligible->whereExists(function ($query): void {
                        $query->selectRaw('1')->from('customer_relationship_owner_assignments as owner')
                            ->whereColumn('owner.customer_id', 'bookings.customer_id')
                            ->whereColumn('owner.company_id', 'bookings.company_id')
                            ->whereNotNull('owner.owner_sales_profile_id')
                            ->whereColumn('owner.effective_from', '<=', 'bookings.completed_at')
                            ->where(fn ($activeAtCompletion) => $activeAtCompletion->whereNull('owner.ended_at')
                                ->orWhereColumn('owner.ended_at', '>=', 'bookings.completed_at'));
                    })->orWhereExists(function ($ownerAfter): void {
                        $ownerAfter->selectRaw('1')->from('customer_relationship_owner_assignments as later_owner')
                            ->whereColumn('later_owner.customer_id', 'bookings.customer_id')
                            ->whereColumn('later_owner.company_id', 'bookings.company_id')
                            ->whereNotNull('later_owner.owner_sales_profile_id')
                            ->whereColumn('later_owner.effective_from', '>', 'bookings.completed_at')
                            ->whereNotExists(function ($newer): void {
                                $newer->selectRaw('1')->from('bookings as newer')
                                    ->whereColumn('newer.company_id', 'bookings.company_id')
                                    ->whereColumn('newer.customer_id', 'bookings.customer_id')
                                    ->where('newer.status', 'completed')->whereNotNull('newer.completed_at')
                                    ->whereColumn('newer.completed_at', '>', 'bookings.completed_at')
                                    ->whereColumn('newer.completed_at', '<=', 'later_owner.effective_from');
                            });
                    });
                })
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('sales_tasks as task')
                        ->whereColumn('task.company_id', 'bookings.company_id')
                        ->whereColumn('task.booking_id', 'bookings.id')
                        ->whereRaw("task.creation_idempotency_key = CONCAT('repeat-booking-follow-up:', bookings.id)");
                })
                ->chunkById(100, function ($bookings) use ($dryRun, $policy, &$count): void {
                    foreach ($bookings as $booking) {
                        $actorUserId = $booking->updated_user_id ?? $booking->created_user_id ?? $policy->approved_by;
                        if (! $actorUserId) continue;
                        if ($dryRun) {
                            $count++;
                            continue;
                        }
                        try {
                            if (app(SalesCrmService::class)->createRepeatBookingFollowup($booking, (string) $actorUserId)) $count++;
                        } catch (\Throwable $e) {
                            Log::error('Scheduled retry could not create a repeat-booking follow-up task', [
                                'booking_id' => $booking->id,
                                'company_id' => $booking->company_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        }

        return $count;
    }

    private function notify(string $userId, SalesTask $task, string $type): void
    {
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\SalesTaskNotice', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $userId, 'data' => ['notification_type' => $type, 'sales_task_id' => $task->id, 'title' => $type === 'sales_task_escalation' ? 'Sales task overdue' : 'Sales task reminder', 'message' => $task->title, 'due_at' => $task->due_at?->toISOString(), 'link' => '/sales/work?task_id='.$task->id, 'useRouter' => true]]);
    }
}
