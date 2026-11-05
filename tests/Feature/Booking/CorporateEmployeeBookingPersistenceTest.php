<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Customer;
use App\Models\User;
use App\Services\BookingFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('saves the employee and customer from the selected corporate employee record', function () {
    $corporate = Corporate::create(['name' => 'Employee Persistence Test']);
    $selectedUser = User::factory()->create();
    $staleUser = User::factory()->create();
    $employee = CorporateEmployee::create([
        'user_id' => $selectedUser->id,
        'corporate_id' => $corporate->id,
        'is_active' => true,
    ]);
    $staleCustomer = Customer::create([
        'user_id' => $staleUser->id,
        'type' => 'business',
        'sub_type' => 'local',
        'category' => 'regular',
    ]);

    $service = app(BookingFlowService::class);
    $params = $service->normalizeCorporateEmployeeReferences([
        'is_corporate_booking' => true,
        'corporate_account_id' => $corporate->id,
        'corporate_employee_id' => $employee->id,
        'employee_id' => $staleUser->id,
        'customer_id' => $staleCustomer->id,
    ]);

    $resolveCustomerId = new ReflectionMethod($service, 'resolveBookingCustomerId');
    $customerId = $resolveCustomerId->invoke($service, $params);

    expect($params['employee_id'])->toBe((string) $selectedUser->id)
        ->and($params['corporate_employee_id'])->toBe((string) $employee->id)
        ->and(Customer::findOrFail($customerId)->user_id)->toBe($selectedUser->id);
});

it('accepts the saved employee user id when hydrating legacy edit selections', function () {
    $corporate = Corporate::create(['name' => 'Legacy Employee Persistence Test']);
    $user = User::factory()->create();
    $employee = CorporateEmployee::create([
        'user_id' => $user->id,
        'corporate_id' => $corporate->id,
        'is_active' => true,
    ]);

    $params = app(BookingFlowService::class)->normalizeCorporateEmployeeReferences([
        'is_corporate_booking' => true,
        'corporate_account_id' => $corporate->id,
        'corporate_employee_id' => $user->id,
        'employee_id' => $user->id,
    ]);

    expect($params['employee_id'])->toBe((string) $user->id)
        ->and($params['corporate_employee_id'])->toBe((string) $employee->id);
});

it('rejects a corporate employee record from a different account', function () {
    $corporate = Corporate::create(['name' => 'Employee Account Boundary Test']);
    $otherCorporate = Corporate::create(['name' => 'Other Employee Account']);
    $user = User::factory()->create();
    $employee = CorporateEmployee::create([
        'user_id' => $user->id,
        'corporate_id' => $otherCorporate->id,
        'is_active' => true,
    ]);

    expect(fn () => app(BookingFlowService::class)->normalizeCorporateEmployeeReferences([
        'is_corporate_booking' => true,
        'corporate_account_id' => $corporate->id,
        'corporate_employee_id' => $employee->id,
    ]))->toThrow(ValidationException::class);
});
