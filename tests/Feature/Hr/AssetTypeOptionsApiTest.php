<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and exactly hydrates active asset types across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    $first = asset_type_option_seed('LAPTOP', 'Laptop');
    $second = asset_type_option_seed('MONITOR', 'Monitor');
    $inactive = asset_type_option_seed('RETIRED', 'Retired type', 'inactive');
    $other = asset_type_option_seed('PRIVATE', 'Private type');
    $url = '/api/hr/assets/type-options?search=top';

    actingAs($admin, 'api')->getJson($url)->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.value', $first)->assertJsonPath('data.data.0.label', 'Laptop · LAPTOP');
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?search=')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.data.0.value', $first);
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?selected_id='.$second)->assertOk()
        ->assertJsonPath('data.0.value', $second);
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?selected_id='.$inactive)->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?selected_id='.$other)->assertOk()->assertJsonPath('data.0.value', $other);
    actingAs($admin, 'api')->getJson('/api/hr/assets/type-options?per_page=51')->assertUnprocessable();
});

it('rejects a type deactivated after lookup without creating an asset request or event', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.advanced_assets' => true]);
    $type = asset_type_option_seed('RETIRED', 'Retired type', 'inactive');

    actingAs($admin, 'api')->postJson('/api/hr/assets/requests', [
        'asset_type_id' => $type, 'request_kind' => 'new_issue', 'reason_code' => 'equipment',
        'reason' => 'Work equipment required', 'required_from' => today()->toDateString(),
    ])->assertUnprocessable();
    expect(DB::table('hr_asset_requests')->exists())->toBeFalse()
        ->and(DB::table('hr_asset_request_events')->exists())->toBeFalse();
});

it('allows replacement requests against active custody across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.advanced_assets' => true]);
    $type = asset_type_option_seed('LAPTOP', 'Laptop');
    $staff = DB::table('staff')->where('user_id', $admin->id)->first();
    $custodyId = (string) Str::uuid();
    DB::table('hr_custody_assignments')->insert([
        'id' => $custodyId, 'staff_id' => $staff->id,
        'custody_type' => 'asset', 'item_code' => 'FOREIGN-DEVICE', 'item_name' => 'Foreign device',
        'assigned_at' => today()->toDateString(), 'status' => 'assigned', 'assigned_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->postJson('/api/hr/assets/requests', [
        'asset_type_id' => $type, 'replaces_custody_assignment_id' => $custodyId,
        'request_kind' => 'replacement', 'reason_code' => 'device_failure',
        'reason' => 'Replacement required', 'required_from' => today()->toDateString(),
    ])->assertCreated();
    actingAs($admin, 'api')->postJson('/api/hr/assets/custody/'.$custodyId.'/acknowledge', [])->assertOk();

    expect(DB::table('hr_asset_requests')->exists())->toBeTrue()
        ->and(DB::table('hr_asset_request_events')->exists())->toBeTrue()
        ->and(DB::table('hr_custody_assignments')->where('id', $custodyId)->value('acknowledged_at'))->not->toBeNull();
});

it('allows a requested inventory item across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.advanced_assets' => true]);
    $type = asset_type_option_seed('LAPTOP', 'Laptop');
    $itemId = (string) Str::uuid();
    DB::table('hr_asset_items')->insert([
        'id' => $itemId, 'asset_type_id' => $type,
        'asset_tag' => 'FOREIGN-ITEM', 'status' => 'available', 'condition_code' => 'serviceable',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->postJson('/api/hr/assets/requests', [
        'asset_type_id' => $type, 'requested_asset_item_id' => $itemId, 'request_kind' => 'new_issue',
        'reason_code' => 'equipment', 'reason' => 'Work equipment required', 'required_from' => today()->toDateString(),
    ])->assertCreated();

    expect(DB::table('hr_asset_requests')->exists())->toBeTrue()
        ->and(DB::table('hr_asset_request_events')->exists())->toBeTrue();
});

it('issues an approved request from installation-wide inventory', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.advanced_assets' => true]);
    $type = asset_type_option_seed('LAPTOP', 'Laptop');
    $itemId = (string) Str::uuid();
    DB::table('hr_asset_items')->insert([
        'id' => $itemId, 'asset_type_id' => $type,
        'asset_tag' => 'FOREIGN-RESERVED', 'status' => 'reserved', 'condition_code' => 'serviceable',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $staffId = DB::table('staff')->where('user_id', $admin->id)->value('id');
    $requestId = (string) Str::uuid();
    DB::table('hr_asset_requests')->insert([
        'id' => $requestId, 'staff_id' => $staffId, 'asset_type_id' => $type,
        'request_kind' => 'new_issue', 'reason_code' => 'equipment', 'reason' => 'Work equipment required',
        'required_from' => today()->toDateString(), 'status' => 'approved', 'requested_by' => $admin->id,
        'approved_by' => $admin->id, 'approved_at' => now(), 'allocated_asset_item_id' => $itemId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->postJson('/api/hr/assets/requests/'.$requestId.'/issue', [
        'condition_snapshot' => ['condition_code' => 'serviceable'],
    ])->assertCreated();

    expect(DB::table('hr_custody_assignments')->where('asset_request_id', $requestId)->exists())->toBeTrue()
        ->and(DB::table('hr_asset_requests')->where('id', $requestId)->value('status'))->toBe('fulfilled');
});

it('returns and updates a linked installation-wide asset item', function () {
    [$admin] = hr_seed_admin_actor();
    $type = asset_type_option_seed('LAPTOP', 'Laptop');
    $itemId = (string) Str::uuid();
    DB::table('hr_asset_items')->insert([
        'id' => $itemId, 'asset_type_id' => $type,
        'asset_tag' => 'FOREIGN-CUSTODY-ASSET', 'status' => 'assigned', 'condition_code' => 'serviceable',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $staffId = DB::table('staff')->where('user_id', $admin->id)->value('id');
    $custodyId = (string) Str::uuid();
    DB::table('hr_custody_assignments')->insert([
        'id' => $custodyId, 'staff_id' => $staffId, 'asset_item_id' => $itemId,
        'custody_type' => 'asset', 'item_code' => 'FOREIGN-CUSTODY-ASSET', 'item_name' => 'Foreign device',
        'assigned_at' => today()->toDateString(), 'status' => 'assigned', 'assigned_by' => $admin->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->postJson('/api/hr/assets/custody/'.$custodyId.'/return', [
        'disposition' => 'available', 'condition_snapshot' => ['condition_code' => 'serviceable'],
        'reason' => 'Return requested',
    ])->assertOk();

    expect(DB::table('hr_custody_assignments')->where('id', $custodyId)->value('status'))->toBe('returned')
        ->and(DB::table('hr_asset_items')->where('id', $itemId)->value('status'))->toBe('available');
});

function asset_type_option_seed(string $code, string $name, string $status = 'active'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_asset_types')->insert([
        'id' => $id, 'code' => $code, 'name' => $name,
        'returnable' => true, 'consumable' => false, 'requires_employee_ack' => true,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
