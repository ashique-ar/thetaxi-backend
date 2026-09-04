<?php

namespace App\Console\Commands;

use App\Models\Booking\BookingCollectionWorkItem;
use App\Models\Booking\BookingPaymentSchedule;
use App\Models\Booking\BookingPaymentScheduleRule;
use App\Services\BookingPaymentLedgerService;
use App\Services\Sales\CollectionWorkAgingService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcessBookingPaymentSchedules extends Command
{
    protected $signature = 'bookings:process-payment-schedules {--dry-run}';
    protected $description = 'Progress collection work and dispatch idempotent handler reminders';

    public function __construct(
        private readonly CollectionWorkAgingService $aging,
        private readonly BookingPaymentLedgerService $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requiredTables = [
            'booking_payment_schedules',
            'booking_collection_work_items',
            'booking_collection_reminder_deliveries',
        ];
        if (collect($requiredTables)->contains(fn (string $table): bool => ! Schema::hasTable($table))) {
            $this->warn('Canonical collection schedule tables are not deployed. Run the reviewed migrations first.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();
        [$rollingRules, $rollingOccurrences, $rollingFailures] = $this->extendRollingHorizons($today, $dryRun);
        $progressed = 0;
        $dispatched = 0;

        BookingPaymentSchedule::query()
            ->with('collectionSalesProfile.staff.user')
            ->withSum('allocations', 'amount')
            ->whereNull('superseded_at')
            ->where('status', '!=', 'superseded')
            ->whereDate('due_date', '<=', $today->copy()->addDays(90)->toDateString())
            ->chunkById(200, function ($schedules) use ($dryRun, $today, &$progressed, &$dispatched): void {
                foreach ($schedules as $schedule) {
                    $workItem = BookingCollectionWorkItem::query()
                        ->where('booking_payment_schedule_id', $schedule->id)
                        ->first();
                    if (! $workItem) {
                        continue;
                    }

                    $aging = $this->aging->derive(
                        (float) ($schedule->source_amount ?? $schedule->amount),
                        (float) ($schedule->allocations_sum_amount ?? 0),
                        $schedule->due_date,
                        $today,
                        (int) $workItem->reminder_offset_days,
                    );
                    $outstanding = $aging['outstanding_amount'];
                    $nextStatus = $aging['work_status'];

                    if ($workItem->status !== $nextStatus) {
                        $progressed++;
                        if (! $dryRun) {
                            $workItem->update([
                                'status' => $nextStatus,
                                'completed_at' => $nextStatus === 'completed' ? now() : null,
                            ]);
                        }
                    }

                    if ($outstanding <= 0 || ! in_array($nextStatus, ['upcoming', 'overdue'], true)) {
                        continue;
                    }

                    $profile = $schedule->collectionSalesProfile;
                    $staff = $profile?->staff;
                    $user = $staff?->user;
                    $profileIsCurrentHandler = $profile
                        && $staff
                        && ! $staff->trashed()
                        && $profile->company_id
                        && (string) $profile->company_id === (string) $schedule->company_id
                        && $profile->status === 'active'
                        && $profile->collection_eligible
                        && $profile->reporting_currency
                        && $profile->effective_from?->lte(now())
                        && (! $profile->effective_until || $profile->effective_until->gt(now()))
                        && (! $staff->employment_ended_at || $staff->employment_ended_at->gt(now()));
                    if (! $profileIsCurrentHandler || ! $user || (string) $workItem->assigned_sales_profile_id !== (string) $profile->id) {
                        continue;
                    }

                    $reminderType = $nextStatus === 'overdue' ? 'overdue' : 'due_soon';
                    $idempotencyKey = implode(':', [
                        'collection-schedule-reminder',
                        $schedule->id,
                        $schedule->due_date->toDateString(),
                        $reminderType,
                        $profile->id,
                    ]);
                    if (DB::table('booking_collection_reminder_deliveries')->where('idempotency_key', $idempotencyKey)->exists()) {
                        continue;
                    }

                    $dispatched++;
                    if ($dryRun) {
                        continue;
                    }

                    DB::transaction(function () use ($schedule, $workItem, $profile, $user, $outstanding, $reminderType, $idempotencyKey): void {
                        $lockedSchedule = BookingPaymentSchedule::query()
                            ->withSum('allocations', 'amount')
                            ->lockForUpdate()
                            ->findOrFail($schedule->id);
                        if (DB::table('booking_collection_reminder_deliveries')->where('idempotency_key', $idempotencyKey)->exists()) {
                            return;
                        }
                        if ((string) $lockedSchedule->collection_sales_profile_id !== (string) $profile->id) {
                            return;
                        }
                        $currentAging = $this->aging->derive(
                            (float) ($lockedSchedule->source_amount ?? $lockedSchedule->amount),
                            (float) ($lockedSchedule->allocations_sum_amount ?? 0),
                            $lockedSchedule->due_date,
                            now(),
                            (int) $workItem->reminder_offset_days,
                        );
                        $outstanding = $currentAging['outstanding_amount'];
                        if ($outstanding <= 0 || ! in_array($currentAging['work_status'], ['upcoming', 'overdue'], true)) {
                            return;
                        }

                        $notificationId = (string) Str::uuid();
                        $payload = [
                            'notification_type' => $reminderType === 'overdue'
                                ? 'booking_payment_overdue'
                                : 'booking_payment_due_soon',
                            'collection_work_item_id' => $workItem->id,
                            'title' => $reminderType === 'overdue' ? 'Collection task overdue' : 'Collection task due soon',
                            'message' => 'Review the assigned collection task in the Sales workspace.',
                        ];
                        $deliveryFacts = [
                            'booking_id' => $lockedSchedule->booking_id,
                            'payment_schedule_id' => $lockedSchedule->id,
                            'assigned_sales_profile_id' => $profile->id,
                            'recipient_user_id' => $user->id,
                            'reminder_type' => $reminderType,
                            'due_date' => $lockedSchedule->due_date->toDateString(),
                            'outstanding_amount' => $outstanding,
                            'source_currency' => strtoupper((string) ($lockedSchedule->source_currency ?: 'LKR')),
                        ];

                        DatabaseNotification::create([
                            'id' => $notificationId,
                            'type' => 'App\\Notifications\\BookingPaymentScheduleNotice',
                            'notifiable_type' => 'App\\Models\\User',
                            'notifiable_id' => $user->id,
                            'data' => $payload,
                        ]);
                        DB::table('booking_collection_reminder_deliveries')->insert([
                            'id' => (string) Str::uuid(),
                            'company_id' => $lockedSchedule->company_id,
                            'booking_id' => $lockedSchedule->booking_id,
                            'booking_payment_schedule_id' => $lockedSchedule->id,
                            'booking_collection_work_item_id' => $workItem->id,
                            'assigned_sales_profile_id' => $profile->id,
                            'recipient_user_id' => $user->id,
                            'reminder_type' => $reminderType,
                            'channel' => 'database',
                            'schedule_due_date' => $lockedSchedule->due_date->toDateString(),
                            'outstanding_amount' => $outstanding,
                            'source_currency' => strtoupper((string) ($lockedSchedule->source_currency ?: 'LKR')),
                            'status' => 'dispatched',
                            'database_notification_id' => $notificationId,
                            'dispatched_at' => now(),
                            'idempotency_key' => $idempotencyKey,
                            'payload_checksum' => hash('sha256', json_encode(
                                ['notification' => $payload, 'delivery' => $deliveryFacts],
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            )),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $lockedSchedule->update([
                            $reminderType === 'overdue' ? 'overdue_notified_at' : 'reminder_sent_at' => now(),
                            'status' => $reminderType === 'overdue' ? 'overdue' : $lockedSchedule->status,
                        ]);
                        $workItem->update(['last_reminded_at' => now()]);
                    });
                }
            });

        $this->info("Extended {$rollingRules} rolling rule(s) by {$rollingOccurrences} occurrence(s); progressed {$progressed} collection task(s); dispatched {$dispatched} handler reminder(s).");

        return $rollingFailures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function extendRollingHorizons($asOf, bool $dryRun): array
    {
        if (config('sales.features.rolling_payment_schedules', false) !== true) {
            return [0, 0, 0];
        }
        if (! Schema::hasTable('booking_payment_schedule_rules')
            || ! Schema::hasColumn('booking_payment_schedules', 'booking_payment_schedule_rule_id')) {
            $this->error('Rolling payment schedules are enabled but their schema is not deployed.');

            return [0, 0, 1];
        }

        $rulesExtended = 0;
        $occurrences = 0;
        $failures = 0;
        BookingPaymentScheduleRule::query()->where('status', 'active')->orderBy('id')
            ->chunkById(100, function ($rules) use ($asOf, $dryRun, &$rulesExtended, &$occurrences, &$failures): void {
                foreach ($rules as $rule) {
                    try {
                        $result = $this->ledger->extendRollingScheduleRule($rule, $asOf, null, $dryRun);
                        if ((int) $result['generated_count'] > 0) {
                            $rulesExtended++;
                            $occurrences += (int) $result['generated_count'];
                        }
                    } catch (\Throwable $exception) {
                        $failures++;
                        $this->error("Rolling rule {$rule->id} was not extended: {$exception->getMessage()}");
                    }
                }
            });

        return [$rulesExtended, $occurrences, $failures];
    }
}
