<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use App\Services\AuthService;
use App\Services\CorporateAccessLinkService;
use Illuminate\Validation\ValidationException;

it('sends a setup link only for an active corporate user with an active portal context', function () {
    $employee = new CorporateEmployee(['is_active' => true]);
    $employee->setRelation('corporate', new Corporate(['is_active' => true]));
    $employee->setRelation('user', new User(['email' => 'employee@example.com', 'is_active' => true]));
    $employee->setRelation('userContext', new UserContext(['is_active' => true]));

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('sendPasswordResetEmail')->once()->with('employee@example.com');
    (new CorporateAccessLinkService($auth))->send($employee);

    $employee->setRelation('userContext', new UserContext(['is_active' => false]));
    expect(fn () => (new CorporateAccessLinkService($auth))->send($employee))
        ->toThrow(ValidationException::class);
});
