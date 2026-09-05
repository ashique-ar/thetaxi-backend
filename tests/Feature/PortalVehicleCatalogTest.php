<?php

use App\Services\BookingFlowService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('loads active fleet vehicles without prefiltering conflicts from portal groups', function () {
    Schema::create('vehicle_groups', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->boolean('is_active')->default(true);
        $table->softDeletes();
    });
    Schema::create('vehicles', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('vehicle_group_id');
        $table->boolean('is_active')->default(true);
        $table->softDeletes();
    });
    DB::table('vehicle_groups')->insert(['id' => 'group-1']);
    DB::table('vehicles')->insert([
        ['id' => 'vehicle-1', 'vehicle_group_id' => 'group-1', 'is_active' => true],
        ['id' => 'vehicle-2', 'vehicle_group_id' => 'group-1', 'is_active' => true],
        ['id' => 'inactive-vehicle', 'vehicle_group_id' => 'group-1', 'is_active' => false],
    ]);

    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
    $query = $service->buildVehicleSearchQuery(Carbon::parse('2026-09-10'), Carbon::parse('2026-09-12'));
    // Isolate the fleet relation from display-only make/model metadata.
    $query->setEagerLoads(['vehicles' => $query->getEagerLoads()['vehicles']]);
    $group = $query->firstOrFail();

    // No bookings table is needed: conflict evaluation belongs to the later
    // availability analysis, not to the catalog relationship query.
    expect($group->vehicles->pluck('id')->sort()->values()->all())
        ->toBe(['vehicle-1', 'vehicle-2']);
});
