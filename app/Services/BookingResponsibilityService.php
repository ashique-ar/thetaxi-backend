<?php

namespace App\Services;

use App\Enums\BookingLifecycleStatus;
use App\Models\Booking\Booking;
use App\Models\User;
use App\Notifications\BookingResponsibilityAssignedNotification;
use App\Notifications\WebsiteBookingNeedsOwnerNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingResponsibilityService
{
    public function eligibleEmployees()
    {
        $employees = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->whereNull('staff.deleted_at')->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->select(['users.id', 'users.first_name', 'users.last_name', 'staff.code'])
            ->orderBy('users.first_name')->orderBy('users.last_name')->get();
        $canViewBookings = User::permission('bookings.view')->whereIn('id', $employees->pluck('id'))->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $employees->whereIn('id', $canViewBookings)->values();
    }

    public function eligibleEmployeesWithOpenBookingCounts(): array
    {
        $employees = $this->eligibleEmployees();
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (string) $id)->all();
        $workload = collect();
        if ($employeeIds) {
            $query = Booking::query()->selectRaw('responsible_user_id, COUNT(*) as open_booking_count')
                ->whereIn('responsible_user_id', $employeeIds)
                ->whereIn('status', array_merge(['inquiry', 'inquiry_qualified'], BookingLifecycleStatus::activeOperationalValues()));
            $workload = $query->groupBy('responsible_user_id')->pluck('open_booking_count', 'responsible_user_id');
        }

        return $employees->map(fn ($employee) => [
            'id' => (string) $employee->id,
            'name' => trim($employee->first_name.' '.$employee->last_name),
            'code' => $employee->code,
            'open_booking_count' => (int) ($workload[$employee->id] ?? 0),
        ])->all();
    }

    public function eligibleEmployeeId(?string $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        return $this->eligibleEmployees()->contains(fn ($employee) => (string) $employee->id === $userId)
            ? $userId
            : null;
    }

    public function notifyResponsibleEmployee(Booking $booking): void
    {
        $userId = $this->eligibleEmployeeId($booking->responsible_user_id);
        if (! $userId) return;

        User::query()->whereKey($userId)->first()?->notify(new BookingResponsibilityAssignedNotification($booking));
    }

    public function notifyBookingCreated(Booking $booking): void
    {
        if ($this->eligibleEmployeeId($booking->responsible_user_id)) {
            $this->notifyResponsibleEmployee($booking);
            return;
        }

        $this->notifyUnassignedBooking($booking);
    }

    public function notifyUnassignedBooking(Booking $booking): void
    {
        if (! in_array($booking->status, array_merge(['inquiry', 'inquiry_qualified'], BookingLifecycleStatus::activeOperationalValues()), true)) {
            return;
        }

        if ($this->eligibleEmployeeId($booking->responsible_user_id)) {
            return;
        }

        try {
            $recipients = User::permission('bookings.view')->permission('bookings.assign')->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereHas('staff', fn ($query) => $query->whereNull('deleted_at')->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
                ->get();
            foreach ($recipients as $recipient) {
                $recipient->notify(new WebsiteBookingNeedsOwnerNotification($booking));
            }
        } catch (\Throwable $exception) {
            Log::warning('Unassigned website booking notification could not be created', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

}
