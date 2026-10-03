<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\StaffAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scopes shared Staff access to the selected identity and fails closed when it is ambiguous', function () {
    $user = User::factory()->create();
    $firstCompany = Company::create(['name' => 'First Staff Access Company']);
    $secondCompany = Company::create(['name' => 'Second Staff Access Company']);
    $firstStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $firstCompany->id]);
    $secondStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $secondCompany->id]);
    $firstContext = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $firstStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $secondContext = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $access = app(StaffAccessService::class);

    expect($access->scope(Staff::query(), $user)->pluck('id'))->toBeEmpty()
        ->and($access->allows($user, $firstStaff, 'view'))->toBeFalse();

    request()->headers->set('X-Active-Context-Type', 'staff');
    request()->headers->set('X-Active-Context-Id', $firstContext->id);
    expect($access->scope(Staff::query(), $user)->pluck('id')->all())->toBe([$firstStaff->id])
        ->and($access->allows($user, $firstStaff, 'view'))->toBeTrue()
        ->and($access->allows($user, $secondStaff, 'view'))->toBeFalse();

    request()->headers->set('X-Active-Context-Id', $secondContext->id);
    expect($access->scope(Staff::query(), $user)->pluck('id')->all())->toBe([$secondStaff->id]);
});
