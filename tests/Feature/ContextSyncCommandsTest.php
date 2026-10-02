<?php

use App\Models\Driver\Driver;
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
