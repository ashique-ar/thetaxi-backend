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
    public function eligibleEmployees(?string $companyId = null)
    {
        $companyId ??= app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (!$companyId) {
            return collect();
        }

        $employees = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $companyId)
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->select(['users.id', 'users.first_name', 'users.last_name', 'staff.code'])
            ->orderBy('users.first_name')->orderBy('users.last_name')->get();
        $canViewBookings = User::permission('bookings.view')->whereIn('id', $employees->pluck('id'))->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $employees->whereIn('id', $canViewBookings)->values();
    }

    public function eligibleEmployeeId(?string $userId, ?string $companyId = null): ?string
    {
        if (!$userId) {
            return null;
        }

        return $this->eligibleEmployees($companyId)->contains(fn ($employee) => (string) $employee->id === $userId)
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

    public function notifyUnassignedBooking(Booking $booking, ?string $companyId = null): void
    {
        $companyId ??= app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (! $companyId || ! in_array($booking->status, BookingLifecycleStatus::activeOperationalValues(), true)) {
            return;
        }

        if ($this->eligibleEmployeeId($booking->responsible_user_id, $companyId)) {
            return;
        }

        try {
            $recipients = User::permission('bookings.view')->permission('bookings.assign')->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereHas('staff', fn ($query) => $query->where('company_id', $companyId)
                    ->whereNull('deleted_at')->whereNull('employment_ended_at'))
                ->get();
            foreach ($recipients as $recipient) {
                $recipient->notify(new WebsiteBookingNeedsOwnerNotification($booking));
            }
        } catch (\Throwable $exception) {
            Log::warning('Unassigned website booking notification could not be created', [
                'booking_id' => $booking->id,
                'company_id' => $companyId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

}
