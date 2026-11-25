<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows internal booking approval notifications to staff contexts but not corporate contexts', function () {
    $user = User::factory()->create();
    $notificationId = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $notificationId,
        'type' => 'App\\Notifications\\BookingApprovalRequested',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode([
            'type' => 'booking_approval_requested',
            'title' => 'Booking #BK-TEST awaiting approval',
            'data' => ['booking_id' => (string) Str::uuid(), 'booking_number' => 'BK-TEST'],
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user, 'api')
        ->withHeaders(['X-Active-Context-Type' => 'staff'])
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.notifications.0.id', $notificationId);

    $this->actingAs($user, 'api')
        ->withHeaders(['X-Active-Context-Type' => 'corporate'])
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 0);
});
