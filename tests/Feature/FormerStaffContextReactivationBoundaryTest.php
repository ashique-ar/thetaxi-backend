<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\UserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
