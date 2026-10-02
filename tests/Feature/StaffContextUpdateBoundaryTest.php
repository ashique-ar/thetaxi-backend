<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('rejects account status changes through Staff profile updates without disabling other contexts', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $admin->givePermissionTo('staff.edit', 'staff.edit-all');
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create([
        'user_id' => $staff->user_id,
        'context_type' => 'customer',
        'context_id' => (string) Str::uuid(),
        'is_active' => true,
    ]);

    actingAs($admin, 'api')->patchJson('/api/staff/'.$staff->id, ['status' => 'inactive'])->assertUnprocessable();

    $this->assertDatabaseHas('users', ['id' => $staff->user_id, 'is_active' => true]);
    $this->assertDatabaseHas('user_contexts', [
        'user_id' => $staff->user_id,
        'context_type' => 'customer',
        'is_active' => true,
    ]);
});
