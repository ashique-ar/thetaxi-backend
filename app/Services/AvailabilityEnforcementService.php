<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use App\Models\Website\WebsiteSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Centralises all non-booking-conflict availability checks:
 *  - Driver licence expiry
 *  - Driver leave & rest-window enforcement
 *  - Vehicle scheduled-maintenance blocking
 *  - Self-driven eligibility validation
 *
 * Every public method returns a structured result array:
 *   ['available' => bool, 'blocking_reasons' => string[], 'warnings' => string[]]
 */
class AvailabilityEnforcementService
{
    // ────────────────────────────────────────────────────────────────
    // Driver checks
    // ────────────────────────────────────────────────────────────────

    /**
     * Run all driver availability checks for the given period.
     * Returns immediately on first blocking condition (short-circuit) if $failFast = true.
     */
    public function checkDriver(Driver $driver, Carbon $from, Carbon $to, bool $failFast = false): array
    {
        $blocking = [];
        $warnings = [];

        // 1. Licence expiry
        $licResult = $this->checkDriverLicence($driver, $to);
        $blocking  = array_merge($blocking, $licResult['blocking_reasons']);
        $warnings  = array_merge($warnings,  $licResult['warnings']);
        if ($failFast && !empty($blocking)) {
            return $this->result(false, $blocking, $warnings);
        }

        // 2. Leave schedule
        $leaveResult = $this->checkDriverLeave($driver, $from, $to);
        $blocking    = array_merge($blocking, $leaveResult['blocking_reasons']);
        $warnings    = array_merge($warnings,  $leaveResult['warnings']);
        if ($failFast && !empty($blocking)) {
            return $this->result(false, $blocking, $warnings);
        }

        // 3. Rest windows (fatigue management)
        $restResult = $this->checkDriverRestWindows($driver, $from, $to);
        $blocking   = array_merge($blocking, $restResult['blocking_reasons']);
        $warnings   = array_merge($warnings,  $restResult['warnings']);

        // 4. Contract expiry
        $contractResult = $this->checkDriverContract($driver, $to);
        $blocking = array_merge($blocking, $contractResult['blocking_reasons']);
        $warnings = array_merge($warnings,  $contractResult['warnings']);

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    /**
     * Licence expiry check.
     * Blocking: licence expired before the end of the booking.
     * Warning: licence expires within the booking period.
     */
    public function checkDriverLicence(Driver $driver, Carbon $bookingEnd): array
    {
        $blocking = [];
        $warnings = [];

        if (!$driver->license_expiry) {
            $warnings[] = 'Driver has no licence expiry date on record — verify manually.';
            return $this->result(true, $blocking, $warnings);
        }

        $expiry = Carbon::parse($driver->license_expiry)->endOfDay();

        if ($expiry->lt(now())) {
            $blocking[] = sprintf(
                'Driver licence expired on %s.',
                $expiry->format('d M Y')
            );
        } elseif ($expiry->lt($bookingEnd)) {
            $blocking[] = sprintf(
                'Driver licence expires on %s, which is before the booking ends on %s.',
                $expiry->format('d M Y'),
                $bookingEnd->format('d M Y')
            );
        } elseif ($expiry->diffInDays($bookingEnd) <= 30) {
            $warnings[] = sprintf(
                'Driver licence expires soon (%s) — renewal recommended.',
                $expiry->format('d M Y')
            );
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    /**
     * Leave schedule check.
     * leave_schedule is stored as a JSON array of objects:
     *   [{"from": "2026-06-01", "to": "2026-06-07", "type": "annual", "note": "..."}]
     */
    public function checkDriverLeave(Driver $driver, Carbon $from, Carbon $to): array
    {
        $blocking = [];
        $warnings = [];

        $leaveSchedule = $this->parseJsonField($driver->leave_schedule);
        if (empty($leaveSchedule)) {
            return $this->result(true, $blocking, $warnings);
        }

        foreach ($leaveSchedule as $leave) {
            $leaveFrom = isset($leave['from']) ? Carbon::parse($leave['from'])->startOfDay() : null;
            $leaveTo   = isset($leave['to'])   ? Carbon::parse($leave['to'])->endOfDay()     : null;

            if (!$leaveFrom || !$leaveTo) {
                continue;
            }

            // Check overlap
            if ($from->lte($leaveTo) && $to->gte($leaveFrom)) {
                $leaveType = ucfirst($leave['type'] ?? 'leave');
                $blocking[] = sprintf(
                    'Driver is on %s from %s to %s.',
                    $leaveType,
                    $leaveFrom->format('d M Y'),
                    $leaveTo->format('d M Y')
                );
            }
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    /**
     * Rest-window check (fatigue management).
     * rest_windows is stored as a JSON array of objects:
     *   [{"start": "2026-06-01 22:00", "end": "2026-06-02 06:00", "reason": "mandatory rest"}]
     */
    public function checkDriverRestWindows(Driver $driver, Carbon $from, Carbon $to): array
    {
        $blocking = [];
        $warnings = [];

        $restWindows = $this->parseJsonField($driver->rest_windows);
        if (empty($restWindows)) {
            return $this->result(true, $blocking, $warnings);
        }

        foreach ($restWindows as $window) {
            $windowStart = isset($window['start']) ? Carbon::parse($window['start']) : null;
            $windowEnd   = isset($window['end'])   ? Carbon::parse($window['end'])   : null;

            if (!$windowStart || !$windowEnd) {
                continue;
            }

            if ($from->lt($windowEnd) && $to->gt($windowStart)) {
                $blocking[] = sprintf(
                    'Driver has a mandatory rest window from %s to %s (%s).',
                    $windowStart->format('d M Y H:i'),
                    $windowEnd->format('d M Y H:i'),
                    $window['reason'] ?? 'fatigue management'
                );
            }
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    /**
     * Contract expiry check.
     */
    public function checkDriverContract(Driver $driver, Carbon $bookingEnd): array
    {
        $blocking = [];
        $warnings = [];

        $expiry = isset($driver->contract_expiry_date)
            ? Carbon::parse($driver->contract_expiry_date)->endOfDay()
            : null;

        if (!$expiry) {
            return $this->result(true, $blocking, $warnings);
        }

        if ($expiry->lt(now())) {
            $blocking[] = sprintf('Driver contract expired on %s.', $expiry->format('d M Y'));
        } elseif ($expiry->lt($bookingEnd)) {
            $warnings[] = sprintf(
                'Driver contract expires on %s before booking ends.',
                $expiry->format('d M Y')
            );
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    // ────────────────────────────────────────────────────────────────
    // Vehicle checks
    // ────────────────────────────────────────────────────────────────

    /**
     * Run all vehicle availability checks for the given period.
     */
    public function checkVehicle(Vehicle $vehicle, Carbon $from, Carbon $to): array
    {
        $blocking = [];
        $warnings = [];

        $maintenanceResult = $this->checkVehicleMaintenance($vehicle, $from, $to);
        $blocking = array_merge($blocking, $maintenanceResult['blocking_reasons']);
        $warnings = array_merge($warnings,  $maintenanceResult['warnings']);

        $insuranceResult = $this->checkVehicleInsurance($vehicle, $to);
        $blocking = array_merge($blocking, $insuranceResult['blocking_reasons']);
        $warnings = array_merge($warnings,  $insuranceResult['warnings']);

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    /**
     * Vehicle scheduled maintenance blocking.
     * Checks both ongoing maintenance records (status = in_progress) and
     * scheduled maintenance windows that fall within the booking period.
     */
    public function checkVehicleMaintenance(Vehicle $vehicle, Carbon $from, Carbon $to): array
    {
        $blocking = [];
        $warnings = [];

        // 1. Active maintenance records (vehicle currently in workshop)
        $activeMaintenanceQuery = DB::table('vehicle_maintenance_records')
            ->where('vehicle_id', $vehicle->id)
            ->where('status', 'in_progress');

        if (Schema::hasColumn('vehicle_maintenance_records', 'completed_date')) {
            $activeMaintenanceQuery->whereNull('completed_date');
        }

        $active = $activeMaintenanceQuery->first();

        if ($active) {
            $blocking[] = sprintf(
                'Vehicle is currently under maintenance (started %s).',
                $active->performed_date
                    ? Carbon::parse($active->performed_date)->format('d M Y')
                    : 'recently'
            );
            return $this->result(false, $blocking, $warnings);
        }

        // 2. Scheduled maintenance that overlaps the booking period
        $scheduleDateColumn = Schema::hasColumn('vehicle_maintenance_schedules', 'scheduled_date')
            ? 'scheduled_date'
            : 'next_due_date';

        $scheduledQuery = DB::table('vehicle_maintenance_schedules')
            ->where('vehicle_id', $vehicle->id)
            ->where($scheduleDateColumn, '<=', $to->toDateString());

        if (Schema::hasColumn('vehicle_maintenance_schedules', 'status')) {
            $scheduledQuery->where('status', 'scheduled');
        }

        if (Schema::hasColumn('vehicle_maintenance_schedules', 'estimated_completion_date')) {
            $scheduledQuery->where(function ($q) use ($from) {
                $q->whereNull('estimated_completion_date')
                    ->orWhere('estimated_completion_date', '>=', $from->toDateString());
            });
        } else {
            $scheduledQuery->where($scheduleDateColumn, '>=', $from->toDateString());
        }

        $scheduledConflicts = $scheduledQuery->get();

        foreach ($scheduledConflicts as $schedule) {
            $schedStart = Carbon::parse($schedule->{$scheduleDateColumn});
            $schedEnd = property_exists($schedule, 'estimated_completion_date') && $schedule->estimated_completion_date
                ? Carbon::parse($schedule->estimated_completion_date)
                : $schedStart->copy()->addDays(1);

            if ($from->lte($schedEnd) && $to->gte($schedStart)) {
                $blocking[] = sprintf(
                    'Vehicle has a scheduled %s maintenance from %s to %s.',
                    ucfirst($schedule->type ?? 'routine'),
                    $schedStart->format('d M Y'),
                    $schedEnd->format('d M Y')
                );
            }
        }

        // 3. Warn if maintenance is due soon after booking
        $dueSoonQuery = DB::table('vehicle_maintenance_schedules')
            ->where('vehicle_id', $vehicle->id)
            ->where($scheduleDateColumn, '>', $to->toDateString())
            ->where($scheduleDateColumn, '<=', $to->copy()->addDays(7)->toDateString());

        if (Schema::hasColumn('vehicle_maintenance_schedules', 'status')) {
            $dueSoonQuery->where('status', 'scheduled');
        }

        $dueSoon = $dueSoonQuery->count();

        if ($dueSoon > 0) {
            $warnings[] = "Vehicle has $dueSoon maintenance session(s) scheduled within 7 days after the booking.";
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    /**
     * Vehicle insurance validity check.
     */
    public function checkVehicleInsurance(Vehicle $vehicle, Carbon $bookingEnd): array
    {
        $blocking = [];
        $warnings = [];

        $expiryColumn = Schema::hasColumn('vehicle_insurances', 'expiry_date')
            ? 'expiry_date'
            : 'end_date';

        $insuranceQuery = DB::table('vehicle_insurances')
            ->where('vehicle_id', $vehicle->id);

        if (Schema::hasColumn('vehicle_insurances', 'is_active')) {
            $insuranceQuery->where('is_active', true);
        }

        $insurance = $insuranceQuery
            ->orderByDesc($expiryColumn)
            ->first();

        if (!$insurance) {
            $warnings[] = 'No active insurance record found for vehicle — verify before dispatch.';
            return $this->result(true, $blocking, $warnings);
        }

        if (empty($insurance->{$expiryColumn})) {
            $warnings[] = 'Active insurance record has no expiry date — verify before dispatch.';
            return $this->result(true, $blocking, $warnings);
        }

        $expiry = Carbon::parse($insurance->{$expiryColumn})->endOfDay();

        if ($expiry->lt(now())) {
            $blocking[] = sprintf('Vehicle insurance expired on %s.', $expiry->format('d M Y'));
        } elseif ($expiry->lt($bookingEnd)) {
            $blocking[] = sprintf(
                'Vehicle insurance expires on %s before the booking ends.',
                $expiry->format('d M Y')
            );
        } elseif ($expiry->diffInDays($bookingEnd) <= 14) {
            $warnings[] = sprintf('Vehicle insurance expires soon (%s).', $expiry->format('d M Y'));
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    // ────────────────────────────────────────────────────────────────
    // Self-driven eligibility
    // ────────────────────────────────────────────────────────────────

    /**
     * Validate whether a customer is eligible to self-drive.
     */
    public function checkSelfDrivenEligibility(Customer $customer, Vehicle $vehicle, Carbon $from): array
    {
        $blocking = [];
        $warnings = [];

        $user = $customer->user;

        // 1. Minimum age
        $minAge = (int) (WebsiteSetting::getValue('self_driven_min_age', 21) ?? 21);
        if ($user?->dob) {
            $age = Carbon::parse($user->dob)->age;
            if ($age < $minAge) {
                $blocking[] = "Customer must be at least $minAge years old to self-drive (currently $age).";
            }
        } else {
            $warnings[] = 'Customer date of birth not on record — minimum age cannot be verified.';
        }

        // 2. Valid driving licence
        $license = DB::table('driving_licenses')
            ->where('customer_id', $customer->id)
            ->where('is_active', true)
            ->orderByDesc('expiry_date')
            ->first();

        if (!$license) {
            $blocking[] = 'No valid driving licence on file for this customer.';
        } elseif ($license->expiry_date && Carbon::parse($license->expiry_date)->isPast()) {
            $blocking[] = sprintf(
                "Customer's driving licence expired on %s.",
                Carbon::parse($license->expiry_date)->format('d M Y')
            );
        }

        // 3. Vehicle self-drive compatible
        if (!$vehicle->self_driven_compatible) {
            $blocking[] = 'This vehicle is not configured for self-drive.';
        }

        // 4. No recent accidents / damage claims on record
        $recentDamage = DB::table('booking_dispatches')
            ->join('booking_items', function ($j) use ($customer) {
                $j->on('booking_dispatches.booking_id', '=', 'booking_items.booking_id')
                  ->where('booking_dispatches.is_self_driven', true);
            })
            ->join('bookings', 'bookings.id', '=', 'booking_dispatches.booking_id')
            ->where('bookings.customer_id', $customer->id)
            ->whereNotNull('booking_dispatches.damages_reported')
            ->where('booking_dispatches.actual_return_at', '>=', now()->subMonths(6))
            ->exists();

        if ($recentDamage) {
            $warnings[] = 'Customer has damage reports on self-driven bookings in the past 6 months.';
        }

        // 5. Minimum deposit availability (from websitesetting)
        $depositRequired = (float) (WebsiteSetting::getValue('self_driven_deposit_required', 0) ?? 0);
        if ($depositRequired > 0) {
            $warnings[] = sprintf(
                'A refundable deposit of %s %s is required for self-drive bookings.',
                WebsiteSetting::getValue('default_currency', 'LKR'),
                number_format($depositRequired, 2)
            );
        }

        return $this->result(empty($blocking), $blocking, $warnings);
    }

    // ────────────────────────────────────────────────────────────────
    // Mileage-based maintenance trigger
    // Called after a trip is returned to check if maintenance is due.
    // ────────────────────────────────────────────────────────────────

    /**
     * Check if a vehicle needs maintenance after a trip (mileage/time triggers).
     * Returns list of triggered schedules that should be activated.
     */
    public function checkPostTripMaintenanceTriggers(Vehicle $vehicle, int $currentMileage): array
    {
        $triggered = [];

        if (!Schema::hasColumn('vehicle_maintenance_schedules', 'trigger_mileage')) {
            return $triggered;
        }

        $schedulesQuery = DB::table('vehicle_maintenance_schedules')
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('trigger_mileage')
            ->where('trigger_mileage', '<=', $currentMileage);

        if (Schema::hasColumn('vehicle_maintenance_schedules', 'status')) {
            $schedulesQuery->where('status', 'scheduled');
        }

        $schedules = $schedulesQuery->get();

        foreach ($schedules as $schedule) {
            $triggered[] = [
                'schedule_id'   => $schedule->id,
                'type'          => $schedule->type,
                'trigger_at_km' => $schedule->trigger_mileage,
                'current_km'    => $currentMileage,
            ];

            $scheduleDateColumn = Schema::hasColumn('vehicle_maintenance_schedules', 'scheduled_date')
                ? 'scheduled_date'
                : 'next_due_date';

            $update = [
                $scheduleDateColumn => now()->toDateString(),
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('vehicle_maintenance_schedules', 'status')) {
                $update['status'] = 'due';
            }

            // Mark schedule as due
            DB::table('vehicle_maintenance_schedules')
                ->where('id', $schedule->id)
                ->update($update);
        }

        if (!empty($triggered)) {
            // Mark vehicle unavailable until maintenance is done
            DB::table('vehicles')
                ->where('id', $vehicle->id)
                ->update([
                    'availability_status' => 'unavailable_maintenance',
                    'updated_at'          => now(),
                ]);

            Log::info('Vehicle flagged for maintenance after trip', [
                'vehicle_id' => $vehicle->id,
                'current_km' => $currentMileage,
                'triggered'  => count($triggered),
            ]);

            // Notify maintenance team by email
            $this->notifyMaintenanceTeam($vehicle, $triggered, $currentMileage);
        }

        return $triggered;
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    /**
     * Send maintenance alert email to the configured maintenance team address.
     */
    private function notifyMaintenanceTeam(Vehicle $vehicle, array $triggered, int $currentMileage): void
    {
        $email = WebsiteSetting::getValue('maintenance_team_email');
        if (empty($email)) {
            Log::debug('Maintenance team email not configured — skipping notification');
            return;
        }

        $plate       = $vehicle->license_plate ?? $vehicle->plate_number ?? $vehicle->id;
        $vehicleDesc = trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '') . ' (' . $plate . ')');
        $count       = count($triggered);
        $types       = implode(', ', array_unique(array_column($triggered, 'type')));

        $body = "Vehicle: {$vehicleDesc}\n"
            . "Current mileage: {$currentMileage} km\n"
            . "Triggered maintenance items ({$count}): {$types}\n\n"
            . "Please schedule the vehicle for servicing as soon as possible.\n";

        try {
            $fromAddress = config('mail.from.address');
            $toRecipients = [$email];

            if (!app()->environment('local', 'testing') && !empty($fromAddress)) {
                $toRecipients[] = $fromAddress;
            }

            Mail::raw($body, function ($message) use ($toRecipients, $fromAddress, $vehicleDesc, $count) {
                $message->to(array_values(array_unique(array_filter($toRecipients))))
                        ->subject("[Maintenance Alert] {$vehicleDesc} — {$count} item(s) due");
                if (!app()->environment('local', 'testing') && !empty($fromAddress)) {
                    $message->replyTo($fromAddress);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Failed to send maintenance team notification', [
                'vehicle_id' => $vehicle->id,
                'email'      => $email,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function result(bool $available, array $blocking, array $warnings): array
    {
        return [
            'available'        => $available,
            'blocking_reasons' => array_values(array_unique($blocking)),
            'warnings'         => array_values(array_unique($warnings)),
        ];
    }

    /** @return array<int, mixed> */
    private function parseJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && !empty($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
