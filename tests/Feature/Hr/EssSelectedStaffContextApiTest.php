<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes self-service requests and history to the selected active Staff context', function () {
    [$user, $firstCompany] = hr_seed_admin_actor([], true);
    $firstStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $secondCompany = App\Models\Company::create(['name' => 'Second ESS Company']);
    $secondStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $secondCompany->id]);
    $firstContext = UserContext::query()->where('user_id', $user->id)->where('context_type', 'staff')->where('context_id', $firstStaff->id)->firstOrFail();
    $secondContext = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $firstRequestId = (string) Str::uuid();
    $secondRequestId = (string) Str::uuid();
    foreach ([[$firstRequestId, $firstCompany->id, $firstStaff->id], [$secondRequestId, $secondCompany->id, $secondStaff->id]] as [$id, $companyId, $staffId]) {
        DB::table('hr_request_index')->insert([
            'id' => $id, 'company_id' => $companyId, 'requester_staff_id' => $staffId,
            'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
            'status' => 'pending_approval', 'summary' => 'Leave request', 'capability_snapshot' => '{}',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $url = '/api/hr/ess/my-requests';
    actingAs($user, 'api')->getJson($url)->assertForbidden();
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => (string) Str::uuid(),
    ])->getJson($url)->assertForbidden();
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $firstContext->id,
    ])->getJson($url)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $firstRequestId);
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $secondContext->id,
    ])->getJson($url)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $secondRequestId);
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $secondContext->id,
    ])->getJson($url.'/'.$firstRequestId)->assertNotFound();
});
