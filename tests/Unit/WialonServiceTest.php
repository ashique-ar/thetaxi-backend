<?php

namespace Tests\Unit;

use App\Services\WialonService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class WialonServiceTest extends TestCase
{
    private const COMPANY = 'c5a60000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('wialon_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('company_id')->unique(); $table->text('token');
            $table->string('base_url'); $table->json('resource_ids')->nullable(); $table->json('group_ids')->nullable(); $table->json('group_mappings')->nullable(); $table->json('unit_ids')->nullable();
            $table->boolean('enabled')->default(true); $table->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('company_id')->nullable(); $table->unsignedBigInteger('wialon_unit_id')->nullable(); $table->softDeletes();
        });
        DB::table('wialon_integrations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => self::COMPANY,
            'token' => encrypt('test-token'), 'base_url' => 'https://hst-api.wialon.com', 'resource_ids' => '[70]', 'group_ids' => '[5]', 'group_mappings' => '[]', 'unit_ids' => '[12]',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_units_login_search_and_close_session(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 12, 'nm' => 'Taxi 12']]])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['error' => 0]);

        $units = app(WialonService::class)->units(self::COMPANY);

        $this->assertSame('Taxi 12', $units[0]['nm']);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'core/search_items'
            && $request['sid'] === 'session-id'
            && json_decode($request['params'], true)['flags'] === 2106625);
    }

    public function test_report_runs_inside_one_session_and_cleans_up_result(): void
    {
        $resources = ['items' => [['id' => 70, 'nm' => 'Reports', 'rep' => ['4' => ['id' => 4, 'n' => 'Trips']]]]];
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['error' => 0])
            ->push(['eid' => 'session-id'])
            ->push($resources)
            ->push(['reportResult' => ['stats' => [], 'tables' => [['name' => 'unit_trips', 'label' => 'Trips', 'rows' => 1]]]])
            ->push([['n' => 0, 'c' => [['t' => 'Trip 1']]]])
            ->push([])
            ->push(['error' => 0]);

        $result = app(WialonService::class)->runReport(self::COMPANY, 12, 70, 4, 100, 200);

        $this->assertSame('Trip 1', $result['tables'][0]['data'][0]['c'][0]['t']);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'report/exec_report'
            && json_decode($request['params'], true)['reportObjectId'] === 12);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'report/cleanup_result');
    }

    public function test_portal_can_set_wialon_mileage_counter_in_kilometers(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['error' => 0])
            ->push(['eid' => 'session-id'])
            ->push(['cnm' => 12000])
            ->push(['error' => 0]);

        $this->assertSame(12000, app(WialonService::class)->setMileage(self::COMPANY, 12, 12000));
        Http::assertSent(fn (Request $request) => $request['svc'] === 'unit/update_mileage_counter'
            && json_decode($request['params'], true) === ['itemId' => 12, 'newValue' => 12000]);
    }

    public function test_mileage_counter_permission_error_is_actionable(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['error' => 0])
            ->push(['eid' => 'session-id'])
            ->push(['error' => 7])
            ->push(['error' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The GPS account needs Edit counters access to this unit in Wialon.');
        app(WialonService::class)->setMileage(self::COMPANY, 12, 12000);
    }

    public function test_telemetry_returns_latest_sensor_and_counter_readings_for_a_selected_unit(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['id' => 12, 'nm' => 'Taxi 12', 'netconn' => 1, 'mu' => 0,
                'pos' => ['t' => 100, 'x' => 79.8, 'y' => 6.9, 's' => 32],
                'cnm' => 1000, 'cneh' => 40, 'lmsg' => ['t' => 101, 'p' => ['fuel' => 25]],
                'sens' => [['id' => 3, 'n' => 'Fuel', 'p' => 'fuel', 'm' => 'L']],
            ])
            ->push(['error' => 0]);

        $data = app(WialonService::class)->telemetry(self::COMPANY, 12);

        $this->assertTrue($data['connected']);
        $this->assertSame(1000.0, $data['counters']['mileage_km']);
        $this->assertSame(25.0, $data['sensors'][0]['value']);
        $this->assertSame(32.0, $data['position']['s']);
    }

    public function test_engine_hours_counter_is_updated_and_confirmed_by_wialon(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['error' => 0])
            ->push(['eid' => 'session-id'])
            ->push(['cneh' => 42])
            ->push(['error' => 0]);

        $this->assertSame(42, app(WialonService::class)->setEngineHours(self::COMPANY, 12, 42));
        Http::assertSent(fn (Request $request) => $request['svc'] === 'unit/update_eh_counter'
            && json_decode($request['params'], true) === ['itemId' => 12, 'newValue' => 42]);
    }

    public function test_only_a_currently_available_configured_command_can_be_sent(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['id' => 12, 'cmds' => [['n' => 'Unlock', 'c' => 'custom_msg', 't' => 'tcp']]])
            ->push([['id' => 8, 'n' => 'Unlock', 'c' => 'custom_msg', 'l' => 'tcp', 'p' => 'unlock', 'f' => 0]])
            ->push([])
            ->push(['error' => 0]);

        $result = app(WialonService::class)->executeCommand(self::COMPANY, 12, 8);

        $this->assertTrue($result['queued']);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'unit/exec_cmd'
            && json_decode($request['params'], true) === [
                'itemId' => 12, 'commandName' => 'Unlock', 'linkType' => 'tcp',
                'param' => 'unlock', 'timeout' => 30, 'flags' => 0,
            ]);
    }

    public function test_alerts_load_supported_detectors_and_return_flat_events(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 5, 'u' => [12]]]])
            ->push(['id' => 12, 'nm' => 'Taxi 12'])
            ->push(['error' => 4])
            ->push(['error' => 0])
            ->push(['selector' => ['trips' => ['0' => [['from' => ['t' => 100], 'to' => ['t' => 200]]]]], 'events' => ['trips' => ['0' => 1]]])
            ->push(['error' => 0])
            ->push(['error' => 0]);

        $result = app(WialonService::class)->alertEvents(self::COMPANY, 12, 100, 200);

        $this->assertSame([['type' => 'trips', 'from' => ['t' => 100], 'to' => ['t' => 200]]], $result['events']);
        Http::assertSentCount(8);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'events/load'
            && collect(json_decode($request['params'], true)['detectors'])->contains(fn (array $detector) => $detector['type'] === 'health_check'));
        Http::assertSent(fn (Request $request) => $request['svc'] === 'events/load'
            && !collect(json_decode($request['params'], true)['detectors'])->contains(fn (array $detector) => $detector['type'] === 'health_check'));
        Http::assertSent(fn (Request $request) => $request['svc'] === 'events/unload');
    }
}
