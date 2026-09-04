<?php

namespace Database\Seeders;

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds one clearly-inactive demo Hikvision connector + device for the
 * default Company so the Attendance Operations / device screens have
 * something to render on a fresh install, without ever attempting to
 * contact a real device (status is "inactive" throughout, so
 * AttendanceProviderManager/adapters that gate on `status === 'active'`
 * will refuse to talk to it).
 *
 * Schema reference: database/migrations/2026_08_13_100000_create_hr_attendance_raw_ledger.php
 * (hr_attendance_connectors, hr_attendance_devices) and the models
 * App\Models\Hr\Attendance\AttendanceConnector / AttendanceDevice, which cast
 * signing_secret / encrypted_configuration as encrypted attributes — using
 * the Eloquent models here (rather than DB::table) so those casts apply and
 * the stored secret round-trips through Laravel's encrypter correctly.
 *
 * Idempotent: guarded by each table's own unique key (connector_key; and
 * provider + serial_number for the device).
 */
class HrAttendanceDeviceDefaultsSeeder extends Seeder
{
    private const CONNECTOR_KEY = 'ATC-DEMO-PLACEHOLDER-0001';
    private const DEVICE_SERIAL = 'DEMO-PLACEHOLDER-0001';

    public function run(): void
    {
        $company = DB::table('companies')->where('is_default', true)->whereNull('deleted_at')->first();
        if (!$company) {
            $this->command?->warn('HR attendance device defaults skipped: no default company exists.');
            return;
        }

        $actor = DB::table('staff')->where('company_id', $company->id)->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if (!$actor) {
            $this->command?->warn('HR attendance device defaults skipped: the default company has no active Staff user for audit ownership.');
            return;
        }

        $organizationUnitId = DB::table('hr_organization_units')->where('company_id', $company->id)->where('code', 'HO')->value('id');

        $connector = AttendanceConnector::firstOrCreate(
            ['connector_key' => self::CONNECTOR_KEY],
            [
                'company_id' => $company->id,
                'name' => 'Demo Hikvision Connector (inactive placeholder)',
                'topology' => 'local_connector',
                'signing_secret' => Str::random(64),
                'status' => 'inactive',
                'allowed_ip_cidrs' => null,
                'capabilities' => ['demo' => true, 'note' => 'Placeholder seed data. Not connected to a real device.'],
                'created_user_id' => $actor,
            ]
        );

        $device = AttendanceDevice::firstOrCreate(
            ['provider' => 'hikvision', 'serial_number' => self::DEVICE_SERIAL],
            [
                'company_id' => $company->id,
                'connector_id' => $connector->id,
                'organization_unit_id' => $organizationUnitId,
                'integration_mode' => 'direct_isapi',
                'model' => 'Demo/Placeholder - not a real device',
                'firmware' => null,
                'site_code' => 'HO-DEMO',
                'timezone' => 'Asia/Colombo',
                'capabilities' => ['demo' => true],
                'status' => 'inactive',
                'created_user_id' => $actor,
            ]
        );

        $this->command?->info("HR attendance device defaults ready for {$company->name}: connector '{$connector->name}' and device '{$device->model}' are seeded as inactive/demo (status=inactive).");
    }
}
