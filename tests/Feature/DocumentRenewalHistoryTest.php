<?php

use App\Models\Document;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\Vehicle\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores driver and vehicle compliance renewal history only in documents', function (): void {
    expect(Schema::hasTable('driver_license_renewals'))->toBeFalse()
        ->and(Schema::hasTable('vehicle_revenue_licenses'))->toBeFalse();

    $user = User::create(['first_name' => 'Driver', 'last_name' => 'One', 'email' => 'driver-one@example.com',
        'phone' => '+94770000003', 'password' => bcrypt('Password1!'), 'is_active' => true]);
    $driver = Driver::create(['user_id' => $user->id, 'license_no' => 'OLD-1', 'license_expiry' => '2027-01-01']);
    $vehicle = Vehicle::create(['title' => 'Test vehicle', 'license_plate' => 'CAB-1000']);

    $oldDriverLicense = $driver->documents()->create([
        'document_type' => 'driver_license', 'document_number' => 'OLD-1', 'expiry_date' => '2027-01-01',
        'disk' => 'public', 'path' => '', 'file_name' => '', 'status' => 'superseded',
    ]);
    $newDriverLicense = $driver->documents()->create([
        'document_type' => 'driver_license', 'document_number' => 'NEW-2', 'expiry_date' => '2032-01-01',
        'disk' => 'public', 'path' => '', 'file_name' => '', 'status' => 'verified',
        'replaces_document_id' => $oldDriverLicense->id,
    ]);
    $oldRevenueLicense = $vehicle->documents()->create([
        'document_type' => 'vehicle_revenue_license', 'document_number' => 'RL-1', 'expiry_date' => '2027-02-01',
        'disk' => 'public', 'path' => '', 'file_name' => '', 'status' => 'superseded',
    ]);
    $newRevenueLicense = $vehicle->documents()->create([
        'document_type' => 'vehicle_revenue_license', 'document_number' => 'RL-2', 'expiry_date' => '2028-02-01',
        'disk' => 'public', 'path' => '', 'file_name' => '', 'status' => 'active',
        'replaces_document_id' => $oldRevenueLicense->id,
    ]);

    expect(Document::count())->toBe(4)
        ->and($newDriverLicense->replaces_document_id)->toBe($oldDriverLicense->id)
        ->and($newRevenueLicense->replaces_document_id)->toBe($oldRevenueLicense->id)
        ->and($vehicle->revenueLicenses()->count())->toBe(2)
        ->and($driver->licenseRenewals()->count())->toBe(2);
});
