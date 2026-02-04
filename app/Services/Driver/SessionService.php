<?php

namespace App\Services\Driver;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSession;
use App\Models\BusinessSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Session Service
 * 
 * Manages driver online/offline sessions including session creation, closure,
 * heartbeat updates, and auto-offline processing.
 * 
 * @see Requirements 4.1, 4.2, 4.3, 4.4, 4.6, 4.7, 5.2, 5.4, 5.5
 */
class SessionService
{
    /**
     * Default auto-offline timeout in minutes.
     */
    private const DEFAULT_AUTO_OFFLINE_TIMEOUT = 10;

    /**
     * Start a new online session for a driver.
     * 
     * Creates a new DriverSession record and updates the Driver's online status.
     *
     * @param Driver $driver The driver going online
     * @param array $data Session data including device_uuid, latitude, longitude
     * @return DriverSession The created session
     * @throws \Exception If driver is already online
     * 
     * @see Requirement 4.1 - Create DriverSession on go-online
     * @see Requirement 4.2 - Capture driver_id, device_uuid, start_time, start coordinates
     * @see Requirement 4.6 - Set is_online to true
     */
    public function startSession(Driver $driver, array $data): DriverSession
    {
        // Check if driver already has an active session
        if ($driver->is_online && $driver->activeSession) {
            throw new \Exception('Driver is already online');
        }

        return DB::transaction(function () use ($driver, $data) {
            $now = Carbon::now();

            // Create new session
            $session = DriverSession::create([
                'driver_id' => $driver->id,
                'device_uuid' => $data['device_uuid'] ?? $driver->current_device_uuid,
                'status' => 'active',
                'start_time' => $now,
                'start_latitude' => $data['latitude'] ?? null,
                'start_longitude' => $data['longitude'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Update driver state
            $driver->update([
                'is_online' => true,
                'last_active_at' => $now,
                'current_latitude' => $data['latitude'] ?? null,
                'current_longitude' => $data['longitude'] ?? null,
                'current_device_uuid' => $data['device_uuid'] ?? $driver->current_device_uuid,
            ]);

            return $session;
        });
    }

    /**
     * End an active session for a driver.
     * 
     * Closes the active DriverSession, calculates distance, and updates Driver state.
     *
     * @param Driver $driver The driver going offline
     * @param array $data Session end data including latitude, longitude
     * @return DriverSession The closed session
     * @throws \Exception If driver has no active session
     * 
     * @see Requirement 4.3 - Close active session on go-offline
     * @see Requirement 4.4 - Capture end_time, end coordinates, total_distance_km
     * @see Requirement 4.7 - Set is_online to false
     */
    public function endSession(Driver $driver, array $data): DriverSession
    {
        $session = $driver->activeSession;

        if (!$session) {
            throw new \Exception('Driver has no active session');
        }

        return DB::transaction(function () use ($driver, $session, $data) {
            $now = Carbon::now();

            // Calculate total distance from route points
            $routeService = app(RouteService::class);
            $totalDistance = $routeService->calculateDistance($session);

            // Update session
            $session->update([
                'status' => 'completed',
                'end_time' => $now,
                'end_latitude' => $data['latitude'] ?? null,
                'end_longitude' => $data['longitude'] ?? null,
                'total_distance_km' => $totalDistance,
            ]);

            // Update driver state
            $driver->update([
                'is_online' => false,
                'last_active_at' => $now,
                'current_latitude' => $data['latitude'] ?? $driver->current_latitude,
                'current_longitude' => $data['longitude'] ?? $driver->current_longitude,
            ]);

            return $session->fresh();
        });
    }

    /**
     * Get the active session for a driver.
     *
     * @param Driver $driver The driver to check
     * @return DriverSession|null The active session or null
     */
    public function getActiveSession(Driver $driver): ?DriverSession
    {
        return $driver->activeSession;
    }

    /**
     * Update the heartbeat timestamp for a driver.
     * 
     * Updates the driver's last_active_at to indicate they are still active.
     *
     * @param Driver $driver The driver sending heartbeat
     * @return void
     * 
     * @see Requirement 5.2 - Update last_active_at on heartbeat
     */
    public function updateHeartbeat(Driver $driver): void
    {
        $driver->update([
            'last_active_at' => Carbon::now(),
        ]);
    }

    /**
     * Process auto-offline for inactive drivers.
     * 
     * Finds drivers whose last_active_at exceeds the timeout threshold
     * and automatically closes their sessions.
     *
     * @return int Number of drivers marked offline
     * 
     * @see Requirement 5.4 - Auto-close sessions on timeout
     * @see Requirement 5.5 - Set is_online to false on auto-offline
     */
    public function processAutoOffline(): int
    {
        $timeout = $this->getAutoOfflineTimeout();
        $cutoffTime = Carbon::now()->subMinutes($timeout);

        // Find online drivers who have exceeded the timeout
        $inactiveDrivers = Driver::where('is_online', true)
            ->where('last_active_at', '<', $cutoffTime)
            ->get();

        $count = 0;

        foreach ($inactiveDrivers as $driver) {
            try {
                $this->autoCloseSession($driver);
                $count++;
            } catch (\Exception $e) {
                // Log error but continue processing other drivers
                Log::error("Failed to auto-offline driver {$driver->id}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Auto-close a driver's session due to inactivity.
     *
     * @param Driver $driver The inactive driver
     * @return DriverSession|null The closed session or null
     */
    protected function autoCloseSession(Driver $driver): ?DriverSession
    {
        $session = $driver->activeSession;

        if (!$session) {
            // Just update driver state if no session exists
            $driver->update(['is_online' => false]);
            return null;
        }

        return DB::transaction(function () use ($driver, $session) {
            $now = Carbon::now();

            // Calculate total distance from route points
            $routeService = app(RouteService::class);
            $totalDistance = $routeService->calculateDistance($session);

            // Update session with auto_closed status
            $session->update([
                'status' => 'auto_closed',
                'end_time' => $now,
                'end_latitude' => $driver->current_latitude,
                'end_longitude' => $driver->current_longitude,
                'total_distance_km' => $totalDistance,
            ]);

            // Update driver state
            $driver->update([
                'is_online' => false,
            ]);

            return $session->fresh();
        });
    }

    /**
     * Get the auto-offline timeout from business settings.
     *
     * @return int Timeout in minutes
     * 
     * @see Requirement 5.6 - Configurable timeout via business settings
     */
    protected function getAutoOfflineTimeout(): int
    {
        $setting = BusinessSetting::getSetting('driver_auto_offline_timeout');
        
        if ($setting !== null && is_numeric($setting)) {
            return (int) $setting;
        }

        return self::DEFAULT_AUTO_OFFLINE_TIMEOUT;
    }

    /**
     * Check if a driver is currently online.
     *
     * @param Driver $driver The driver to check
     * @return bool True if online, false otherwise
     */
    public function isOnline(Driver $driver): bool
    {
        return $driver->is_online && $driver->activeSession !== null;
    }

    /**
     * Get session statistics for a driver.
     *
     * @param Driver $driver The driver
     * @param Carbon|null $from Start date for statistics
     * @param Carbon|null $to End date for statistics
     * @return array Session statistics
     */
    public function getSessionStats(Driver $driver, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = $driver->sessions()->completed();

        if ($from) {
            $query->where('start_time', '>=', $from);
        }

        if ($to) {
            $query->where('end_time', '<=', $to);
        }

        $sessions = $query->get();

        $totalOnlineSeconds = $sessions->sum(function ($session) {
            if ($session->start_time && $session->end_time) {
                return $session->end_time->diffInSeconds($session->start_time);
            }
            return 0;
        });

        $sessionCount = $sessions->count();
        $averageDurationSeconds = $sessionCount > 0 ? $totalOnlineSeconds / $sessionCount : 0;
        $totalDistanceKm = $sessions->sum('total_distance_km');

        return [
            'session_count' => $sessionCount,
            'total_online_seconds' => $totalOnlineSeconds,
            'total_online_hours' => round($totalOnlineSeconds / 3600, 2),
            'average_duration_seconds' => round($averageDurationSeconds),
            'average_duration_minutes' => round($averageDurationSeconds / 60, 2),
            'total_distance_km' => round($totalDistanceKm, 2),
        ];
    }
}
