<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    Schema::dropIfExists('bookings');
    Schema::dropIfExists('booking_approvals');
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('password');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->uuid('notifiable_id');
        $table->json('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    Schema::create('bookings', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('booking_number')->nullable();
        $table->uuid('corporate_account_id')->nullable();
        $table->string('status')->nullable();
        $table->boolean('requires_approval')->default(false);
        $table->string('approval_status')->nullable();
        $table->string('corporate_approval_status')->nullable();
        $table->softDeletes();
    });
    Schema::create('booking_approvals', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->string('status');
        $table->timestamps();
        $table->softDeletes();
    });
})->afterEach(function () {
    Schema::dropIfExists('booking_approvals');
    Schema::dropIfExists('bookings');
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('users');
});

it('shows internal booking approval notifications to staff contexts but not corporate contexts', function () {
    $user = new User();
    $user->forceFill([
        'id' => (string) Str::uuid(),
        'email' => 'staff@example.test',
        'password' => 'unused',
        'is_active' => true,
    ]);
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
