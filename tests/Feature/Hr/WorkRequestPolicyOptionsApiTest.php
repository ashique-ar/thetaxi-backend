<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns approved installation-wide policies covering the selected Staff request interval', function () {
    [$admin] = hr_seed_admin_actor();
    $staff = App\Models\Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $first = hr_work_policy_option_seed($admin->id, 'OT-A');
    $second = hr_work_policy_option_seed($admin->id, 'OT-B');
    $third = hr_work_policy_option_seed($admin->id, 'OT-C');
    $pending = hr_work_policy_option_seed($admin->id, 'OT-PENDING', 'pending_approval');
    $expired = hr_work_policy_option_seed($admin->id, 'OT-OLD', 'approved', today()->subDays(2)->toDateString(), today()->toDateString());
    $wrongKind = hr_work_policy_option_seed($admin->id, 'REMOTE', 'approved', null, null, 'remote_work');
    $start = today()->toDateString().'T09:00:00';
    $end = today()->toDateString().'T10:00:00';
    $url = '/api/hr/workforce/work-request-policy-options?staff_id='.$staff->id.'&request_kind=overtime&starts_at='.urlencode($start).'&ends_at='.urlencode($end).'&search=OT';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 3)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'OT-A v1 - overtime');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=3')->assertOk()
        ->assertJsonPath('data.data.0.value', $third);
    foreach ([$pending, $expired, $wrongKind] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/workforce/work-request-policy-options?staff_id='.$staff->id.'&request_kind=overtime&starts_at='.urlencode($start).'&ends_at='.urlencode($end).'&selected_id='.$excluded)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/workforce/work-request-policy-options')->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function hr_work_policy_option_seed(string $actorId, string $code, string $status = 'approved', ?string $effectiveFrom = null, ?string $effectiveUntil = null, string $kind = 'overtime'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_work_request_policies')->insert([
        'id' => $id, 'request_kind' => $kind, 'code' => $code, 'version' => 1,
        'rules' => json_encode([], JSON_THROW_ON_ERROR), 'effective_from' => $effectiveFrom ?? today()->subDay()->toDateString(),
        'effective_until' => $effectiveUntil, 'status' => $status, 'created_by' => $actorId,
        'approved_by' => $status === 'approved' ? $actorId : null, 'approved_at' => $status === 'approved' ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
