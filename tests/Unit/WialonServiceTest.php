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
        DB::table('wialon_integrations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => self::COMPANY,
            'token' => encrypt('test-token'), 'base_url' => 'https://hst-api.wialon.com', 'resource_ids' => '[70]', 'group_ids' => '[]', 'group_mappings' => '[]', 'unit_ids' => '[12]',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_units_login_search_and_close_session(): void
    {
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push(['items' => [['id' => 12, 'nm' => 'Taxi 12']]])
            ->push(['']);

        $units = app(WialonService::class)->units(self::COMPANY);

        $this->assertSame('Taxi 12', $units[0]['nm']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'core/search_items'
            && $request['sid'] === 'session-id'
            && json_decode($request['params'], true)['flags'] === 2106625);
    }

    public function test_report_runs_inside_one_session_and_cleans_up_result(): void
    {
        $resources = ['items' => [['id' => 70, 'nm' => 'Reports', 'rep' => ['4' => ['id' => 4, 'n' => 'Trips']]]]];
        Http::fakeSequence()
            ->push(['eid' => 'session-id'])
            ->push($resources)
            ->push(['reportResult' => ['stats' => [], 'tables' => [['name' => 'unit_trips', 'label' => 'Trips', 'rows' => 1]]]])
            ->push([['n' => 0, 'c' => [['t' => 'Trip 1']]]])
            ->push([])
            ->push([]);

        $result = app(WialonService::class)->runReport(self::COMPANY, 12, 70, 4, 100, 200);

        $this->assertSame('Trip 1', $result['tables'][0]['data'][0]['c'][0]['t']);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'report/exec_report'
            && json_decode($request['params'], true)['reportObjectId'] === 12);
        Http::assertSent(fn (Request $request) => $request['svc'] === 'report/cleanup_result');
    }

    public function test_portal_can_set_wialon_mileage_counter_in_kilometers(): void
    {
        Http::fakeSequence()->push(['eid' => 'session-id'])->push(['cnm' => 12000])->push([]);

        $this->assertSame(12000, app(WialonService::class)->setMileage(self::COMPANY, 12, 12000));
        Http::assertSent(fn (Request $request) => $request['svc'] === 'unit/update_mileage_counter'
            && json_decode($request['params'], true) === ['itemId' => 12, 'newValue' => 12000]);
    }
}
