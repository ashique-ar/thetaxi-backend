<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists only travel requests in the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.travel.view');
    $firstStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondCompany = Company::create(['name' => 'Selected travel company']);
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondStaff->update(['company_id' => $secondCompany->id]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();

    $requestIds = [];
    foreach ([[$firstCompany->id, $firstStaff->id], [$secondCompany->id, $secondStaff->id]] as [$companyId, $staffId]) {
        $id = (string) Str::uuid();
        DB::table('hr_travel_requests')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staffId, 'request_number' => 'TR-'.$id,
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
