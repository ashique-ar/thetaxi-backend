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
