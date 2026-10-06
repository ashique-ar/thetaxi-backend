<?php

namespace Tests\Feature;

use App\Models\Vehicle\Vehicle;
use App\Services\WialonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WialonFleetSyncTest extends TestCase
{
    private const COMPANY = 'c5a60000-0000-4000-8000-000000000001';

    public function test_sync_imports_a_unit_once_and_preserves_later_portal_mileage(): void
    {
        Schema::create('wialon_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->uuid('company_id')->unique(); $table->text('token');
            $table->string('base_url'); $table->json('resource_ids')->nullable(); $table->json('group_ids')->nullable(); $table->json('group_mappings')->nullable(); $table->json('unit_ids')->nullable();
            $table->boolean('enabled')->default(true); $table->timestamps();
        });
        DB::table('wialon_integrations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => self::COMPANY,
            'token' => encrypt('test-token'), 'base_url' => 'https://hst-api.wialon.com', 'resource_ids' => '[]', 'group_ids' => '[10]', 'group_mappings' => json_encode([['wialon_group_id' => 10, 'vehicle_group_id' => 'c5a60000-0000-4000-8000-000000000002']]), 'unit_ids' => '[]',
            'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('vehicle_groups', function (Blueprint $table) {
            $table->uuid('id')->primary(); $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        DB::table('vehicle_groups')->insert(['id' => 'c5a60000-0000-4000-8000-000000000002', 'is_active' => true]);
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('wialon_unit_id')->nullable()->unique();
            $table->string('wialon_unique_id')->nullable();
            $table->unsignedBigInteger('wialon_hw_type_id')->nullable();
            $table->decimal('wialon_mileage', 12, 2)->nullable();
            $table->timestamp('wialon_last_message_at')->nullable();
            $table->timestamp('wialon_last_synced_at')->nullable();
            $table->string('title')->nullable();
            $table->uuid('company_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('registration_no')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('availability_status')->default('available');
            $table->integer('initial_mileage')->nullable();
            $table->integer('current_mileage')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->timestamps();
        });
        $unit = ['id' => 456, 'nm' => 'Fleet 456', 'uid' => 'imei456', 'hw' => 9, 'mu' => 0,
            'pos' => ['t' => now()->timestamp], 'counters' => ['cnm' => 13500.4, 'cnm_km' => 13500.4]];
        $groups = ['items' => [['id' => 10, 'nm' => 'Company Cars', 'u' => [456]]]];
        Http::fakeSequence()
            ->push(['eid' => 'first-session'])->push($groups)->push(['items' => [$unit]])->push([])
            ->push(['eid' => 'second-session'])->push($groups)->push(['items' => [$unit]])->push([]);

        $this->assertSame(1, app(WialonService::class)->syncVehicles(self::COMPANY));
        $this->assertSame(1, DB::table('vehicles')->count());
        $this->assertSame(456, (int) DB::table('vehicles')->value('wialon_unit_id'));
        $this->assertSame(self::COMPANY, DB::table('vehicles')->value('company_id'));
        $this->assertSame('c5a60000-0000-4000-8000-000000000002', DB::table('vehicles')->value('vehicle_group_id'));
        $vehicle = Vehicle::withInactive()->withTrashed()->where('wialon_unit_id', 456)->firstOrFail();
        $this->assertFalse($vehicle->is_active);
        $this->assertSame('unavailable_offline', $vehicle->availability_status);
        $this->assertSame(13500, $vehicle->initial_mileage);
        $this->assertSame(13500, $vehicle->current_mileage);
        $vehicle->current_mileage = 14000;
        $vehicle->save();

        $this->assertSame(0, app(WialonService::class)->syncVehicles(self::COMPANY));
        $this->assertSame(14000, $vehicle->fresh()->current_mileage);
        $this->assertSame(13500.4, (float) $vehicle->fresh()->wialon_mileage);
        $this->assertDatabaseCount('vehicles', 1);
    }
}
