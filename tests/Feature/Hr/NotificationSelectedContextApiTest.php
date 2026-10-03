<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('reads and saves notification preferences for only the selected Staff identity', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.notifications.preferences');
    config(['hr.features.engagement_analytics' => true]);
    $firstStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondCompany = Company::create(['name' => 'Second Notification company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $preferenceIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$preferenceIds[0], $firstStaff, $firstCompany->id], [$preferenceIds[1], $secondStaff, $secondCompany->id]] as [$id, $staff, $companyId]) {
        DB::table('hr_notification_preferences')->insert([
            'id' => $id, 'company_id' => $companyId, 'staff_id' => $staff->id,
            'event_type' => 'optional_update', 'channel' => 'email', 'enabled' => true,
            'updated_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/notifications/preferences')->assertForbidden();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/notifications/preferences')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.enabled', true);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/notifications/preferences', [
        'event_type' => 'optional_update', 'channel' => 'email', 'enabled' => false,
    ])->assertOk();

    expect(DB::table('hr_notification_preferences')->where('id', $preferenceIds[0])->value('enabled'))->toBe(1)
        ->and(DB::table('hr_notification_preferences')->where('id', $preferenceIds[1])->value('enabled'))->toBe(0);
});
