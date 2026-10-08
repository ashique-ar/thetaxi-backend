<?php

use App\Services\Hr\JobPositionAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('blocks job and position writes for inactive or deleted companies', function () {
    [$actor, $company] = hr_seed_admin_actor(['name' => 'Inactive job administration company']);
    $service = app(JobPositionAdministrationService::class);
    $id = (string) Str::uuid();
    $commands = [
        fn () => $service->createCatalog('job_family', [], $company->id, $actor->id),
        fn () => $service->updateCatalog('job_family', $id, [], $company->id, $actor->id),
        fn () => $service->createPosition([], $company->id, $actor->id),
        fn () => $service->updatePosition($id, [], $company->id, $actor->id),
    ];

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    foreach ($commands as $command) {
        expect(fn () => $command())->toThrow(HttpException::class, 'HR job and position writes require an active legal entity.');
    }
    expect(DB::table('hr_organization_change_events')->where('company_id', $company->id)->exists())->toBeFalse();

    DB::table('companies')->where('id', $company->id)->update(['is_active' => true, 'deleted_at' => now()]);
    foreach ($commands as $command) {
        expect(fn () => $command())->toThrow(HttpException::class, 'HR job and position writes require an active legal entity.');
    }
});
