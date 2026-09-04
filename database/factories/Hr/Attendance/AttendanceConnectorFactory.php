<?php

namespace Database\Factories\Hr\Attendance;

use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Hr\Attendance\AttendanceConnector>
 */
class AttendanceConnectorFactory extends Factory
{
    protected $model = AttendanceConnector::class;

    /**
     * Define the model's default state.
     *
     * `company_id` defaults to an existing Company row (or a minimal one
     * created on the fly) for standalone use; override it explicitly to
     * pin the connector to a specific legal entity.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::create(['name' => 'Factory Test Company', 'is_default' => true])->id,
            'name' => 'Factory Hikvision Connector',
            'topology' => 'direct_isapi',
            'connector_key' => 'ATC-'.strtoupper(Str::random(24)),
            'signing_secret' => bin2hex(random_bytes(32)),
            'status' => 'active',
            'allowed_ip_cidrs' => null,
            'last_heartbeat_at' => null,
            'capabilities' => null,
            'created_user_id' => User::factory(),
        ];
    }
}
