<?php

use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use Illuminate\Routing\Route;

function staffRoute(string $method, string $uri): Route
{
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn (Route $candidate) => in_array($method, $candidate->methods(), true)
            && $candidate->uri() === $uri);

    expect($route)->not->toBeNull();

    return $route;
}

it('protects each staff action with its own permission', function () {
    $expected = [
        ['GET', 'api/staff', 'staff.view'],
        ['POST', 'api/staff', 'staff.create'],
        ['GET', 'api/staff/{staff}', 'staff.view'],
        ['PUT', 'api/staff/{staff}', 'staff.edit'],
        ['DELETE', 'api/staff/{staff}', 'staff.terminate'],
        ['GET', 'api/staff/available-users', 'staff.create'],
    ];

    foreach ($expected as [$method, $uri, $permission]) {
        expect(staffRoute($method, $uri)->gatherMiddleware())
            ->toContain("permission:{$permission}");
    }
});

it('keeps the staff profile contract separate from iam roles and bank data', function () {
    $createRules = (new CreateStaffRequest)->rules();
    $updateRules = (new UpdateStaffRequest)->rules();

    expect($createRules)
        ->toHaveKeys(['user_id', 'staff_type', 'code', 'dob', 'city'])
        ->not->toHaveKeys(['role_id', 'employee_id', 'payment_methods'])
        ->and($updateRules)
        ->toHaveKeys(['staff_type', 'code', 'dob', 'city'])
        ->not->toHaveKeys(['user_id', 'role_id', 'employee_id', 'payment_methods']);
});

it('uses the authenticated actor for atomic staff context onboarding', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));
    $contextService = file_get_contents(app_path('Services/UserContextService.php'));

    expect($controller)
        ->toContain("\$data['created_user_id'] = \$request->user()->id")
        ->toContain('$this->contextService->switchContext(')
        ->and($contextService)
        ->toContain('?string $actorUserId = null')
        ->toContain("'created_user_id' => \$actorUserId");
});

it('does not call unfinished hr endpoints from the current staff profile', function () {
    $portalRoot = dirname(base_path());
    $detail = file_get_contents($portalRoot.'/portal-thetaxi/src/app/modules/staff/components/staff-detail/staff-detail.component.ts');
    $form = file_get_contents($portalRoot.'/portal-thetaxi/src/app/modules/staff/components/staff-form/staff-form.component.ts');

    expect($detail)
        ->not->toContain('getStaffSchedule(')
        ->not->toContain('getStaffLeave(')
        ->not->toContain('getStaffPerformance(')
        ->not->toContain('getStaffDocuments(')
        ->not->toContain('getStaffAttendance(')
        ->and($form)
        ->toContain('payload.user_id = raw.user_id')
        ->toContain('staff_type: raw.staff_type!.trim()')
        ->not->toContain('payment_methods');
});
