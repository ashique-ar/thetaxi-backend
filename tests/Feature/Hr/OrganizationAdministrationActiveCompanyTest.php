<?php

use App\Services\Hr\OrganizationAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('blocks every organization administration write for inactive or deleted companies', function () {
    [$actor, $company] = hr_seed_admin_actor(['name' => 'Inactive HR administration company']);
    $service = app(OrganizationAdministrationService::class);
    $id = (string) Str::uuid();
    $commands = [
        fn () => $service->createUnit([], $company->id, $actor->id),
        fn () => $service->updateUnit($id, [], $company->id, $actor->id),
        fn () => $service->createDefinition([], $company->id, $actor->id),
        fn () => $service->updateDefinition($id, [], $company->id, $actor->id),
        fn () => $service->createPayrollGroup([], $company->id, $actor->id),
        fn () => $service->updatePayrollGroup($id, [], $company->id, $actor->id),
        fn () => $service->createDocumentType([], $company->id, $actor->id),
        fn () => $service->updateDocumentType($id, [], $company->id, $actor->id),
        fn () => $service->createWorkCalendar([], $company->id, $actor->id),
        fn () => $service->createWorkCalendarDay($id, [], $company->id, $actor->id),
    ];

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    foreach ($commands as $command) {
        expect(fn () => $command())->toThrow(HttpException::class, 'HR organization writes require an active legal entity.');
    }
    expect(DB::table('hr_organization_change_events')->where('company_id', $company->id)->exists())->toBeFalse();

    DB::table('companies')->where('id', $company->id)->update(['is_active' => true, 'deleted_at' => now()]);
    expect(fn () => $service->createWorkCalendarDay($id, [], $company->id, $actor->id))
        ->toThrow(HttpException::class, 'HR organization writes require an active legal entity.');
});
