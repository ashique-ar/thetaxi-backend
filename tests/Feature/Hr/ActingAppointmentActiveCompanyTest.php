<?php

use App\Services\Hr\ActingAppointmentAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('blocks acting-appointment requests and approvals for inactive or deleted companies', function () {
    [$actor, $company] = hr_seed_admin_actor(['name' => 'Inactive acting-appointment company']);
    $service = app(ActingAppointmentAdministrationService::class);
    $id = (string) Str::uuid();
    $commands = [
        fn () => $service->create([], $company->id, $actor->id),
        fn () => $service->approve($id, [], $company->id, $actor->id),
    ];

    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    foreach ($commands as $command) {
        expect(fn () => $command())->toThrow(HttpException::class, 'Acting appointments require an active legal entity.');
    }
    expect(DB::table('hr_acting_appointment_events')->exists())->toBeFalse();

    DB::table('companies')->where('id', $company->id)->update(['is_active' => true, 'deleted_at' => now()]);
    foreach ($commands as $command) {
        expect(fn () => $command())->toThrow(HttpException::class, 'Acting appointments require an active legal entity.');
    }
});
