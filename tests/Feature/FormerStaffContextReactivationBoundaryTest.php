<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\UserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('requires approved rehire before reactivating a former Staff context', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->former()->create(['user_id' => $user->id]);
    $staff->delete();
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => false,
    ]);
    $service = app(UserContextService::class);

    expect(collect($service->getActivatableContexts($user))->pluck('value')->all())->not->toContain('staff')
        ->and(fn () => $service->switchContext($user, 'staff'))->toThrow(HttpException::class);

    expect($context->fresh()->is_active)->toBeFalse()
        ->and(Staff::withTrashed()->findOrFail($staff->id)->trashed())->toBeTrue();
});

it('does not restore a former Staff model through an already active context', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->former()->create(['user_id' => $user->id]);
    $staff->delete();
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => true,
    ]);

    expect(fn () => app(UserContextService::class)->switchContext($user, 'staff'))
        ->toThrow(HttpException::class);

    expect($context->fresh()->is_active)->toBeTrue()
        ->and(Staff::withTrashed()->findOrFail($staff->id)->trashed())->toBeTrue();
});

it('does not recreate Staff access while synchronizing a Staff-mapped role', function () {
    $user = User::factory()->create();
    $staff = Staff::factory()->former()->create(['user_id' => $user->id]);
    $staff->delete();
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => false,
    ]);
    $role = Role::create(['name' => 'rehire-boundary-staff', 'guard_name' => 'api']);
    $role->context_types = json_encode(['staff']);
    $role->save();

    expect(fn () => app(UserContextService::class)->syncContextsForAssignedRoles($user, [$role]))
        ->toThrow(HttpException::class);

    expect($context->fresh()->is_active)->toBeFalse()
        ->and(UserContext::query()->where('user_id', $user->id)->where('context_type', 'staff')->where('is_active', true)->exists())->toBeFalse()
        ->and(Staff::withTrashed()->where('user_id', $user->id)->count())->toBe(1);

    $activeUser = User::factory()->create();
    $activeStaff = Staff::factory()->former()->create(['user_id' => $activeUser->id]);
    $activeStaff->delete();
    $activeContext = UserContext::create([
        'user_id' => $activeUser->id,
        'context_type' => 'staff',
        'context_id' => $activeStaff->id,
        'is_active' => true,
    ]);

    expect(fn () => app(UserContextService::class)->syncContextsForAssignedRoles($activeUser, [$role]))
        ->toThrow(HttpException::class);

    expect($activeContext->fresh()->is_active)->toBeTrue()
        ->and(Staff::withTrashed()->findOrFail($activeStaff->id)->trashed())->toBeTrue();
});
