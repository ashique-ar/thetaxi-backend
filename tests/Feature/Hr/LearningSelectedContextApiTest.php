<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows Learning records across the installation under the selected Staff identity', function () {
    [$admin] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.learning.view');
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $firstCourse = (string) Str::uuid();
    $secondCourse = (string) Str::uuid();
    foreach ([[$firstCourse, 'FIRST'], [$secondCourse, 'SECOND']] as [$id, $code]) {
        DB::table('hr_courses')->insert([
            'id' => $id, 'code' => $code, 'title' => $code.' course',
            'certification' => false, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/learning/courses')->assertOk();
    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/learning/courses')
        ->assertOk()->assertJsonCount(2, 'data.data')->assertJsonFragment(['id' => $firstCourse])
        ->assertJsonFragment(['id' => $secondCourse]);
});
