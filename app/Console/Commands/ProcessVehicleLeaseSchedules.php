<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vehicle\VehicleLease;
use App\Models\Vehicle\VehicleLeaseEvent;
use App\Models\Vehicle\VehicleLeaseSchedule;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcessVehicleLeaseSchedules extends Command
{
    protected $signature = 'vehicles:process-lease-schedules {--dry-run}';
    protected $description = 'Update lease expiry and notify responsible users about upcoming and overdue vehicle lease instalments';

    public function handle(): int
    {
        if (!Schema::hasTable('vehicle_leases') || !Schema::hasTable('vehicle_lease_schedules')) {
            $this->warn('Vehicle lease tables are not deployed. Run migrations first.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();
        $processed = 0;

        VehicleLease::query()
            ->where('status', 'active')
            ->whereDate('end_date', '<', $today->toDateString())
            ->chunkById(200, function ($leases) use ($dryRun, &$processed) {
                foreach ($leases as $lease) {
                    $processed++;
                    if ($dryRun) {
                        continue;
                    }
                    $lease->update(['status' => 'expired', 'expired_at' => now()]);
                    VehicleLeaseEvent::create([
                        'vehicle_lease_id' => $lease->id,
                        'event_type' => 'expired',
                        'from_status' => 'active',
                        'to_status' => 'expired',
                        'data' => ['end_date' => $lease->end_date->toDateString()],
                        'occurred_at' => now(),
                    ]);
                }
            });

        VehicleLease::query()
            ->where('status', 'active')
            ->whereNull('expiry_reminder_sent_at')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $today->copy()->addDays(90)->toDateString())
            ->with('vehicle:id,title,license_plate,registration_no')
            ->chunkById(200, function ($leases) use ($dryRun, $today, &$processed) {
                foreach ($leases as $lease) {
                    if ($lease->end_date->startOfDay()->diffInDays($today) > (int) $lease->reminder_days) {
                        continue;
                    }
                    $processed++;
                    if ($dryRun) {
                        continue;
                    }
                    $this->notify($lease->created_user_id, [
                        'notification_type' => 'vehicle_lease_expiring',
                        'vehicle_lease_id' => $lease->id,
                        'vehicle_id' => $lease->vehicle_id,
                        'lease_number' => $lease->lease_number,
                        'end_date' => $lease->end_date->toDateString(),
                        'title' => 'Vehicle lease expiring',
                        'message' => "{$lease->lease_number} ends on {$lease->end_date->toDateString()}",
                    ]);
                    $lease->update(['expiry_reminder_sent_at' => now()]);
                }
            });

        VehicleLeaseSchedule::query()
            ->with(['lease.vehicle:id,title,license_plate,registration_no'])
            ->withSum([
                'allocations as allocations_sum_amount' => fn ($query) => $query->whereNull('reversed_at'),
            ], 'amount')
            ->whereIn('status', ['scheduled', 'partially_paid', 'overdue'])
            ->whereDate('due_date', '<=', $today->copy()->addDays(90)->toDateString())
            ->chunkById(200, function ($schedules) use ($dryRun, $today, &$processed) {
                foreach ($schedules as $schedule) {
                    $lease = $schedule->lease;
                    if (!$lease || !in_array($lease->status, ['active', 'expired'], true)) {
                        continue;
                    }
                    $balance = max(0, round((float) $schedule->amount_due - (float) ($schedule->allocations_sum_amount ?? 0), 2));
                    if ($balance <= 0) {
                        continue;
                    }
                    $dueDate = $schedule->due_date->startOfDay();
                    $overdue = $dueDate->lt($today);
                    $upcoming = !$overdue && $dueDate->diffInDays($today) <= (int) $lease->reminder_days;
                    $column = $overdue ? 'overdue_notified_at' : 'reminder_sent_at';
                    if ((!$overdue && !$upcoming) || $schedule->{$column}) {
                        continue;
                    }
                    $processed++;
                    if ($dryRun) {
                        continue;
                    }
                    $this->notify($lease->created_user_id, [
                        'notification_type' => $overdue ? 'vehicle_lease_payment_overdue' : 'vehicle_lease_payment_due_soon',
                        'vehicle_lease_id' => $lease->id,
                        'vehicle_id' => $lease->vehicle_id,
                        'lease_number' => $lease->lease_number,
                        'vehicle_lease_schedule_id' => $schedule->id,
                        'due_date' => $schedule->due_date->toDateString(),
                        'balance_amount' => $balance,
                        'title' => $overdue ? 'Vehicle lease instalment overdue' : 'Vehicle lease instalment due soon',
                        'message' => "{$lease->lease_number}: {$balance} due {$schedule->due_date->toDateString()}",
                    ]);
                    $schedule->update([
                        $column => now(),
                        'status' => $overdue ? 'overdue' : $schedule->status,
                    ]);
                }
            });

        $this->info("Processed {$processed} vehicle lease schedule action(s).");
        return self::SUCCESS;
    }

    private function notify(?string $userId, array $data): void
    {
        if (!$userId || !User::query()->whereKey($userId)->exists()) {
            return;
        }

        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\VehicleLeaseScheduleNotice',
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => $data,
        ]);
    }
}
