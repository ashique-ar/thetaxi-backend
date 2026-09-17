<?php

namespace Tests\Feature;

use App\Models\Driver\Driver;
use App\Models\User;
use App\Notifications\DriverLicenseExpiryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DriverLicenseReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_driver_receives_one_license_reminder(): void
    {
        Notification::fake();
        $user = User::create(['first_name' => 'Due', 'last_name' => 'Driver', 'email' => 'due@example.test', 'password' => bcrypt('password'), 'is_active' => true]);
        $driver = Driver::create([
            'user_id' => $user->id, 'license_no' => 'B1234',
            'license_expiry' => today()->addDays(10), 'license_reminder_days' => 30, 'is_active' => true,
        ]);

        $this->artisan('drivers:send-license-reminders')->assertSuccessful();

        Notification::assertSentTo($user, DriverLicenseExpiryNotification::class);
        $this->assertNotNull($driver->fresh()->license_last_reminded_on);
    }
}
