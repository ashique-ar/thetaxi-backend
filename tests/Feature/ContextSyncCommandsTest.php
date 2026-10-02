<?php

use App\Models\Driver\Driver;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('syncs driver contexts idempotently through the single-type command', function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'api']);
    $user = User::factory()->create();
    $driver = Driver::create(['user_id' => $user->id]);

    Artisan::call('contexts:sync-drivers');
    Artisan::call('contexts:sync-drivers');

    $context = UserContext::where('user_id', $user->id)->where('context_type', 'driver')->sole();
    expect($context->context_id)->toBe($driver->id)
        ->and($context->roles()->where('name', 'driver')->exists())->toBeTrue();
});

it('syncs driver contexts through the all-contexts command', function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'api']);
    $user = User::factory()->create();
    $driver = Driver::create(['user_id' => $user->id]);

    Artisan::call('contexts:sync-all');

    $context = UserContext::where('user_id', $user->id)->where('context_type', 'driver')->sole();
    expect($context->context_id)->toBe($driver->id)
        ->and($context->roles()->where('name', 'driver')->exists())->toBeTrue();
});

it('deactivates ended Staff contexts during a forced all-context sync', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->former()->create(['user_id' => $user->id]);
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => true,
    ]);

    Artisan::call('contexts:sync-all', ['--force' => true]);

    expect($context->fresh()->is_active)->toBeFalse()
        ->and($staff->fresh()->employment_ended_at)->not->toBeNull();
});
