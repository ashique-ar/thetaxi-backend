<?php

namespace App\Console\Commands;

use App\Models\Sales\SalesReportingAssignment;
use App\Models\Sales\SalesTask;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcessSalesTasks extends Command
{
    protected $signature = 'sales:process-tasks {--dry-run}';
    protected $description = 'Dispatch due Sales task reminders and manager escalations';

    public function handle(): int
    {
        if (! Schema::hasTable('sales_tasks') || ! Schema::hasTable('sales_company_feature_settings')
            || ! config('sales.features.crm', false)) return self::SUCCESS;
        $dryRun = (bool) $this->option('dry-run'); $count = 0;
        SalesTask::query()->whereIn('status', ['open', 'in_progress'])
            ->whereIn('company_id', DB::table('sales_company_feature_settings')->select('company_id')
                ->where('feature_key', 'crm')->where('status', 'approved')->where('enabled', true))
            ->where(fn ($q) => $q->where(fn ($r) => $r->whereNull('reminder_dispatched_at')->whereNotNull('remind_at')->where('remind_at', '<=', now()))
                ->orWhere(fn ($r) => $r->whereNull('escalated_at')->whereNotNull('escalate_at')->where('escalate_at', '<=', now())))
            ->chunkById(200, function ($tasks) use ($dryRun, &$count): void {
                foreach ($tasks as $task) {
                    $task->load('owner');
                    $ownerUser = $task->owner?->staff?->user;
                    if (! $task->reminder_dispatched_at && $task->remind_at?->lte(now()) && $ownerUser) {
                        $count++; if (! $dryRun) { $this->notify($ownerUser->id, $task, 'sales_task_reminder'); $task->update(['reminder_dispatched_at' => now()]); }
                    }
                    if (! $task->escalated_at && $task->escalate_at?->lte(now())) {
                        $managerProfile = SalesReportingAssignment::query()->where('member_sales_profile_id', $task->owner_sales_profile_id)
                            ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                            ->with('manager.staff.user')->first()?->manager;
                        if ($managerProfile?->staff?->user) {
                            $count++; if (! $dryRun) { $this->notify($managerProfile->staff->user->id, $task, 'sales_task_escalation'); $task->update(['escalated_at' => now()]); }
                        }
                    }
                }
            });
        $this->info("Processed {$count} Sales task notification(s).");
        return self::SUCCESS;
    }

    private function notify(string $userId, SalesTask $task, string $type): void
    {
        DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\SalesTaskNotice', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $userId, 'data' => ['notification_type' => $type, 'sales_task_id' => $task->id, 'title' => $type === 'sales_task_escalation' ? 'Sales task overdue' : 'Sales task reminder', 'message' => $task->title, 'due_at' => $task->due_at?->toISOString()]]);
    }
}
