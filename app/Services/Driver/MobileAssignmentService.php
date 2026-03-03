<?php

namespace App\Services\Driver;

use App\Enums\TripPhase;
use App\Events\AssignmentStatusChanged;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mobile Assignment Service
 *
 * Handles driver-facing assignment operations for the mobile API:
 * listing, accepting, declining, and creating assignments.
 *
 * @see Requirements 1.1, 1.2, 1.4, 1.5, 1.6, 2.1–2.5, 12.7
 */
class MobileAssignmentService
{
    public function __construct(
        private NotificationTriggerService $notificationService
    ) {}

    /**
     * Get paginated assignments for a driver with optional status filter.
     */
    public function getDriverAssignments(Driver $driver, array $filters = []): LengthAwarePaginator
    {
        $query = DriverAssignment::where('driver_id', $driver->id)
            ->with([
                'booking',
                'bookingItem',
                'bookingItem.vehicle.owner',
                'bookingItem.vehicle.group',
            ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderBy('assigned_from', 'desc');

        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * Get the current active assignment for a driver (time-bounded, status active).
     */
    public function getCurrentAssignment(Driver $driver): ?DriverAssignment
    {
        $now = Carbon::now();

        return DriverAssignment::where('driver_id', $driver->id)
            ->where('status', 'active')
            ->where('assigned_from', '<=', $now)
            ->where('assigned_to', '>=', $now)
            ->with([
                'booking',
                'bookingItem',
                'bookingItem.vehicle.owner',
                'bookingItem.vehicle.group',
            ])
            ->first();
    }

    /**
     * Accept a driver assignment.
     *
     * Validates state, transitions to confirmed, sets trip_phase to accepted,
     * and links the active DriverSession.
     *
     * @throws \InvalidArgumentException
     */
    public function acceptAssignment(Driver $driver, DriverAssignment $assignment): DriverAssignment
    {
        if ($assignment->status === 'confirmed') {
            throw new \InvalidArgumentException('ASSIGNMENT_ALREADY_CONFIRMED');
        }

        if (!in_array($assignment->status, ['active', 'pending_approval'])) {
            throw new \InvalidArgumentException('ASSIGNMENT_INVALID_STATE');
        }

        return DB::transaction(function () use ($driver, $assignment) {
            $now = Carbon::now();

            $assignment->update([
                'status' => 'confirmed',
                'confirmed_at' => $now,
                'confirmed_by' => $driver->user_id,
                'trip_phase' => TripPhase::ACCEPTED,
            ]);

            // Link active session to assignment
            $session = $driver->activeSession;
            if ($session) {
                $session->update(['assignment_id' => $assignment->id]);
            }

            $updated = $assignment->fresh([
                'booking',
                'bookingItem',
                'bookingItem.vehicle.owner',
                'bookingItem.vehicle.group',
            ]);

            // Broadcast status change to admin panel for real-time sync
            $this->broadcastStatusChange($updated, 'accepted', $driver);

            return $updated;
        });
    }

    /**
     * Decline a driver assignment.
     *
     * @throws \InvalidArgumentException
     */
    public function declineAssignment(Driver $driver, DriverAssignment $assignment, string $reason): DriverAssignment
    {
        if (!in_array($assignment->status, ['active', 'pending_approval'])) {
            throw new \InvalidArgumentException('ASSIGNMENT_INVALID_STATE');
        }

        $assignment->update([
            'status' => 'declined',
            'trip_phase' => TripPhase::DECLINED,
            'decline_reason' => $reason,
        ]);

        $updated = $assignment->fresh();

        // Broadcast status change to admin panel for real-time sync
        $this->broadcastStatusChange($updated, 'declined', $driver);

        return $updated;
    }

    /**
     * Get all drivers with computed Driver_Status (online/offline/on_hire).
     */
    public function getDriversWithStatus(): Collection
    {
        return Driver::with(['activeSession'])
            ->get()
            ->map(function (Driver $driver) {
                $status = 'offline';

                if ($driver->is_online && $driver->activeSession) {
                    // Check if driver has an in-progress trip
                    $hasActiveTrip = DriverAssignment::where('driver_id', $driver->id)
                        ->where('trip_phase', TripPhase::IN_PROGRESS)
                        ->exists();

                    $status = $hasActiveTrip ? 'on_hire' : 'online';
                }

                $driver->setAttribute('driver_status', $status);

                return $driver;
            });
    }

    /**
     * Create a new driver assignment and trigger notification.
     */
    public function createAssignment(array $data): DriverAssignment
    {
        return DB::transaction(function () use ($data) {
            $assignment = DriverAssignment::create(array_merge($data, [
                'trip_phase' => TripPhase::ACTIVE,
                'status' => $data['status'] ?? 'active',
            ]));

            // Trigger notification to driver
            $this->notificationService->sendAssignmentNotification($assignment);

            return $assignment;
        });
    }

    /**
     * Broadcast assignment status change to the admin panel channel.
     *
     * Fires an AssignmentStatusChanged event so the admin panel
     * receives real-time updates when a driver accepts or declines.
     *
     * @see Requirements 14.5, 14.6
     */
    private function broadcastStatusChange(DriverAssignment $assignment, string $action, Driver $driver): void
    {
        try {
            broadcast(new AssignmentStatusChanged([
                'assignment_id' => $assignment->id,
                'booking_id' => $assignment->booking_id,
                'booking_item_id' => $assignment->booking_item_id,
                'driver_id' => $driver->id,
                'driver_name' => $driver->user
                    ? trim($driver->user->first_name . ' ' . $driver->user->last_name)
                    : ($driver->code ?? 'Unknown'),
                'action' => $action,
                'status' => $assignment->status,
                'trip_phase' => $assignment->trip_phase?->value,
                'updated_at' => $assignment->updated_at?->toIso8601String(),
            ]));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast assignment status change to admin', [
                'assignment_id' => $assignment->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
