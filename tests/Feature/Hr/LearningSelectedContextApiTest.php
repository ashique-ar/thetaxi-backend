<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes Learning reads to the selected Staff company and rejects ambiguous identity', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.learning.view');
    $secondCompany = Company::create(['name' => 'Second Learning company']);
    $secondStaff = Staff::factory()->create(['user_id' => $admin->id, 'company_id' => $secondCompany->id]);
    $context = UserContext::create([
        'user_id' => $admin->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    $firstCourse = (string) Str::uuid();
    $secondCourse = (string) Str::uuid();
    foreach ([[$firstCourse, $firstCompany->id, 'FIRST'], [$secondCourse, $secondCompany->id, 'SECOND']] as [$id, $companyId, $code]) {
        DB::table('hr_courses')->insert([
            'id' => $id, 'company_id' => $companyId, 'code' => $code, 'title' => $code.' course',
            'certification' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/learning/courses')->assertForbidden();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/learning/courses')
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $secondCourse);
});
