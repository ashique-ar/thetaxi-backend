<?php

namespace Database\Factories\Hr\Attendance;

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hr\Attendance\AttendanceDevice>
 */
class AttendanceDeviceFactory extends Factory
{
    protected $model = AttendanceDevice::class;

    /**
     * Define the model's default state.
     *
     * `company_id` defaults to an existing Company row (or a minimal one
     * created on the fly); `connector_id` is left null since a device does
     * not require one (see AttendanceDeviceController::storeDevice, where
     * it's nullable). Override `company_id` explicitly to pin the device to
     * the same legal entity as the Staff/actor a test is exercising.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::create(['name' => 'Factory Test Company', 'is_default' => true])->id,
            'connector_id' => null,
            'organization_unit_id' => null,
            'provider' => 'hikvision',
            'integration_mode' => 'direct_isapi',
            'model' => 'DS-K1T341AMF',
            'serial_number' => fake()->unique()->numerify('SN##########'),
            'firmware' => 'V2.3.1',
            'site_code' => fake()->randomElement(['HQ', 'DEPOT-A', 'DEPOT-B']),
            'timezone' => 'Asia/Colombo',
            'capabilities' => ['card_management' => true, 'pin_management' => true],
            'encrypted_configuration' => ['ip_address' => fake()->localIpv4(), 'port' => 80, 'username' => 'admin'],
            'status' => 'active',
            'last_sync_at' => null,
            'last_event_at' => null,
            'created_user_id' => User::factory(),
        ];
    }
}
