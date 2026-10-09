<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('limits approval queues to the selected Staff company', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.governance.view', 'hr.engagement.approve', 'hr.recognition.approve', 'hr.wellness.case.manage']);
    $secondCompany = Company::create(['name' => 'Second Governance company']);
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondStaff->update(['company_id' => $secondCompany->id]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $announcementIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$announcementIds[0], $firstCompany->id], [$announcementIds[1], $secondCompany->id]] as [$id, $companyId]) {
        DB::table('hr_announcements')->insert([
            'id' => $id, 'company_id' => $companyId, 'title' => 'Pending announcement', 'body' => 'Review required',
            'audience' => json_encode(['all' => true], JSON_THROW_ON_ERROR), 'priority' => 'normal',
            'acknowledgement_required' => false, 'publish_at' => now(), 'source_timezone' => 'UTC',
            'status' => 'pending_approval', 'created_by' => $admin->id,
            'content_checksum' => hash('sha256', $id), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/governance/queues')->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/governance/queues')->assertOk()
        ->assertJsonPath('data.counts.announcements', 1)
        ->assertJsonPath('data.queues.announcements.0.id', $announcementIds[1])
        ->assertJsonMissingPath('data.queues.announcements.0.created_by');
});

it('omits Staff identifiers and private details from governance queues', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo(['hr.governance.view', 'hr.recognition.approve', 'hr.wellness.case.manage']);
    $staff = Staff::query()->where('user_id', $admin->id)->where('company_id', $company->id)->firstOrFail();
    $otherStaff = Staff::factory()->create(['company_id' => $company->id]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->where('context_id', $staff->id)->firstOrFail();
    $nominationId = (string) Str::uuid();
    DB::table('hr_recognition_nominations')->insert([
        'id' => $nominationId, 'company_id' => $company->id, 'nominee_staff_id' => $staff->id,
        'nominator_staff_id' => $otherStaff->id, 'category' => 'service', 'citation' => 'Private nomination narrative',
        'visibility' => 'manager', 'status' => 'pending_approval', 'reward_proposal' => json_encode(['amount' => 100]),
        'nomination_checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_wellness_referrals')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'referral_type' => 'counselling', 'encrypted_details' => encrypt('Private wellness details'),
        'status' => 'requested', 'consent_status' => 'pending', 'requested_by' => $admin->id,
        'case_owner_staff_id' => $otherStaff->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/governance/queues')->assertOk()
        ->assertJsonPath('data.queues.recognition.0.id', $nominationId)
        ->assertJsonPath('data.queues.recognition.0.category', 'service')
        ->assertJsonMissingPath('data.queues.recognition.0.nominee_staff_id')
        ->assertJsonMissingPath('data.queues.recognition.0.nominator_staff_id')
        ->assertJsonMissingPath('data.queues.recognition.0.citation')
        ->assertJsonMissingPath('data.queues.recognition.0.reward_proposal')
        ->assertJsonPath('data.queues.wellness.0.referral_type', 'counselling')
        ->assertJsonMissingPath('data.queues.wellness.0.staff_id')
        ->assertJsonMissingPath('data.queues.wellness.0.case_owner_staff_id')
        ->assertJsonMissingPath('data.queues.wellness.0.program_id');
    expect($response->getContent())->not->toContain((string) $staff->id, (string) $otherStaff->id, 'Private nomination narrative', 'Private wellness details');
});
