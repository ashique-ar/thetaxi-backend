<?php

namespace App\Console\Commands;

use App\Models\Booking\BookingPaymentSchedule;
use App\Models\Staff;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class ProcessBookingPaymentSchedules extends Command
{
    protected $signature = 'bookings:process-payment-schedules {--dry-run}';
    protected $description = 'Notify booking owners about upcoming and overdue scheduled collections';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (!Schema::hasTable('booking_payment_schedules')) {
            $this->warn('Payment schedule tables are not deployed. Run migrations first.');
            return self::SUCCESS;
        }
        $today = now()->startOfDay();
        $count = 0;
        BookingPaymentSchedule::query()
            ->with(['booking.commissionOwnerStaff.user'])
            ->withSum('allocations', 'amount')
            ->whereDate('due_date', '<=', $today->copy()->addDays(30)->toDateString())
            ->chunkById(200, function ($schedules) use ($dryRun, $today, &$count) {
                foreach ($schedules as $schedule) {
                    $balance = max(0, round((float) $schedule->amount - (float) ($schedule->allocations_sum_amount ?? 0), 2));
                    if ($balance <= 0) continue;
                    $booking = $schedule->booking;
                    $user = $booking?->commissionOwnerStaff?->user
                        ?: ($booking?->created_user_id
                            ? Staff::query()->with('user')->where('user_id', $booking->created_user_id)->first()?->user
                            : null);
                    if (!$user) continue;
                    $dueDate = $schedule->due_date->startOfDay();
                    $reminderDays = (int) ($booking->payment_schedule_reminder_days ?? 3);
                    $overdue = $dueDate->lt($today);
                    $upcoming = !$overdue && $dueDate->diffInDays($today) <= $reminderDays;
                    $column = $overdue ? 'overdue_notified_at' : 'reminder_sent_at';
                    if ((!$overdue && !$upcoming) || $schedule->{$column}) continue;
                    $count++;
                    if ($dryRun) continue;
                    DatabaseNotification::create([
                        'id' => (string) Str::uuid(),
                        'type' => 'App\\Notifications\\BookingPaymentScheduleNotice',
                        'notifiable_type' => 'App\\Models\\User',
                        'notifiable_id' => $user->id,
                        'data' => [
                            'notification_type' => $overdue ? 'booking_payment_overdue' : 'booking_payment_due_soon',
                            'booking_id' => $booking->id,
                            'booking_number' => $booking->booking_number,
                            'payment_schedule_id' => $schedule->id,
                            'due_date' => $schedule->due_date->toDateString(),
                            'balance_amount' => $balance,
                            'title' => $overdue ? 'Booking payment overdue' : 'Booking payment due soon',
                            'message' => "{$booking->booking_number}: {$balance} due {$schedule->due_date->toDateString()}",
                        ],
                    ]);
                    $schedule->update(array_filter([
                        $column => now(),
                        'status' => $overdue ? 'overdue' : null,
                    ], fn ($value) => $value !== null));
                }
            });
        $this->info("Processed {$count} payment schedule notification(s).");
        return self::SUCCESS;
    }
}
