<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists travel requests for the selected Staff identity across the installation', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.travel.view');
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $firstStaff = Staff::factory()->create();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();

    $requestIds = [];
    foreach ([$firstStaff->id, $secondStaff->id] as $staffId) {
        $id = (string) Str::uuid();
        DB::table('hr_travel_requests')->insert([
            'id' => $id, 'staff_id' => $staffId, 'request_number' => 'TR-'.$id,
            'purpose' => 'Business travel', 'origin' => 'Colombo', 'destination' => 'Kandy',
            'departs_at' => now()->addDay(), 'returns_at' => now()->addDays(2), 'timezone' => 'Asia/Colombo',
            'itinerary' => '[]', 'currency' => 'LKR', 'estimated_cost' => 100, 'requested_advance' => 0,
            'allocation_snapshot' => '[]', 'status' => 'pending_approval', 'requested_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $requestIds[] = $id;
    }

    actingAs($admin, 'api')->getJson('/api/hr/travel/requests')->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/travel/requests')->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $requestIds[1]);
});
