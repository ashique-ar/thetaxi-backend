<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps approved attendance policy history immutable and audits pending policy edits', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.attendance_results' => true]);
    $now = now();
    $approvedId = (string) Str::uuid();
    $pendingId = (string) Str::uuid();
    $base = [
        'company_id' => $company->id,
        'code' => 'standard',
        'name' => 'Standard attendance',
        'rules' => json_encode(['maximum_payable_minutes' => 480], JSON_THROW_ON_ERROR),
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'created_by' => $admin->id,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    DB::table('hr_attendance_policies')->insert($base + [
        'id' => $approvedId,
        'status' => 'approved',
        'approved_by' => $admin->id,
        'approved_at' => $now,
    ]);
    DB::table('hr_attendance_policies')->insert(array_merge($base, [
        'id' => $pendingId,
        'code' => 'draft',
        'status' => 'pending_approval',
        'approved_by' => null,
        'approved_at' => null,
    ]));

    $payload = [
        'code' => 'standard-updated',
        'name' => 'Updated attendance',
        'rules' => ['maximum_payable_minutes' => 420],
        'effective_from' => '2026-02-01',
        'effective_until' => null,
    ];

    actingAs($admin, 'api')->putJson("/api/hr/attendance/policies/{$approvedId}", $payload)->assertStatus(409);
    $this->assertSame('Standard attendance', DB::table('hr_attendance_policies')->where('id', $approvedId)->value('name'));

    actingAs($admin, 'api')->putJson("/api/hr/attendance/policies/{$pendingId}", $payload)->assertOk();
    $this->assertSame('Updated attendance', DB::table('hr_attendance_policies')->where('id', $pendingId)->value('name'));
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hr-attendance',
        'description' => 'attendance_policy_updated',
    ]);

    $created = actingAs($admin, 'api')->postJson('/api/hr/attendance/policies', [
        'company_id' => $company->id,
        'code' => 'new-policy',
        'name' => 'New attendance policy',
        'rules' => ['maximum_payable_minutes' => 450],
        'effective_from' => '2026-03-01',
        'effective_until' => null,
    ])->assertCreated()->json('data');
    $this->assertSame('pending_approval', $created['status']);
    $createEvent = DB::table('activity_log')
        ->where('log_name', 'hr-attendance')
        ->where('description', 'attendance_policy_created')
        ->latest('id')->first();
    $this->assertNotNull($createEvent);
    $this->assertSame($created['id'], json_decode($createEvent->properties, true)['policy_id']);
});

it('replays attendance policy approval for its original approver and audits it once', function () {
    [$creator, $company] = hr_seed_admin_actor();
    $approver = \App\Models\User::factory()->create();
    $approver->assignRole('admin');
    $staff = \App\Models\Staff::factory()->create([
        'user_id' => $approver->id, 'company_id' => $company->id, 'staff_type' => 'admin',
    ]);
    \App\Models\UserContext::create([
        'user_id' => $approver->id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $approver->id,
    ]);
    $policyId = (string) Str::uuid();
    DB::table('hr_attendance_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'code' => 'retry-safe', 'name' => 'Retry safe policy',
        'rules' => json_encode(['maximum_payable_minutes' => 480], JSON_THROW_ON_ERROR), 'effective_from' => '2026-01-01',
        'status' => 'pending_approval', 'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = "/api/hr/attendance/policies/{$policyId}/approve";

    actingAs($approver, 'api')->postJson($url, [])->assertOk()->assertJsonPath('data.status', 'approved');
    $approvedAt = DB::table('hr_attendance_policies')->where('id', $policyId)->value('approved_at');
    actingAs($approver, 'api')->postJson($url, [])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(DB::table('hr_attendance_policies')->where('id', $policyId)->value('approved_at'))->toBe($approvedAt);
    $audit = DB::table('activity_log')->where('description', 'attendance_policy_approved')->first();
    expect(json_decode($audit->properties, true))->toBe([
        'company_id' => $company->id, 'status' => 'approved', 'effective_from' => '2026-01-01',
    ])->and(DB::table('activity_log')->where('description', 'attendance_policy_approved')->count())->toBe(1);

    $otherApprover = \App\Models\User::factory()->create();
    $otherApprover->assignRole('admin');
    $otherStaff = \App\Models\Staff::factory()->create([
        'user_id' => $otherApprover->id, 'company_id' => $company->id, 'staff_type' => 'admin',
    ]);
    \App\Models\UserContext::create([
        'user_id' => $otherApprover->id, 'context_type' => 'staff', 'context_id' => $otherStaff->id,
        'is_active' => true, 'created_user_id' => $otherApprover->id,
    ]);
    actingAs($otherApprover, 'api')->postJson($url, [])->assertStatus(409);
});
