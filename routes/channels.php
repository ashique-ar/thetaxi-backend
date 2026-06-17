<?php

use App\Models\Driver\Driver;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Register all broadcast channel authorization callbacks. These channels
| are used for real-time notifications to driver mobile apps and admin panels.
|
*/

/**
 * Private channel for driver assignment notifications.
 *
 * Channel: driver.{driverId}.assignments
 *
 * Authorizes the authenticated driver to receive assignment events
 * on their personal channel.
 *
 * @see Requirements 14.1, 14.3, 14.4
 */
Broadcast::channel('driver.{driverId}.assignments', function ($user, string $driverId) {
    $driver = Driver::where('user_id', $user->id)->first();

    return $driver && $driver->id === $driverId;
});

/**
 * Private channel for admin panel assignment status updates.
 *
 * Channel: admin.assignments
 *
 * Authorizes admin users to receive real-time assignment status changes
 * (accept, decline) from drivers.
 *
 * @see Requirements 14.5, 14.6
 */
Broadcast::channel('admin.assignments', function ($user) {
    return $user->hasAnyPermission(['bookings.view', 'bookings.create', 'bookings.edit']);
});

/**
 * Private channel for per-user database notifications broadcast via Laravel Echo.
 * This is the default channel used by Laravel's Notifiable trait; authorizes the
 * authenticated user to listen to their own notification stream.
 */
Broadcast::channel('App.Models.User.{id}', function ($user, string $id) {
    return (string) $user->id === $id;
});
