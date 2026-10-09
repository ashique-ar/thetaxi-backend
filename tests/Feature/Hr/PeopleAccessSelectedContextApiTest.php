<?php

use App\Models\Company;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes People Core to the selected active Staff identity and rejects ambiguous requests', function () {
    [$user, $firstCompany] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $secondCompany = Company::create(['name' => 'Second People Company']);
    $secondStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $secondStaff->update(['company_id' => $secondCompany->id]);
    $spell = HrEmploymentSpell::factory()->create([
        'staff_id' => $secondStaff->id,
        'company_id' => $secondCompany->id,
        'status' => 'active',
    ]);
    HrEmploymentAssignment::factory()->create([
        'employment_spell_id' => $spell->id,
        'staff_id' => $secondStaff->id,
        'company_id' => $secondCompany->id,
        'effective_from' => now()->subDay(),
        'effective_until' => null,
    ]);
    $context = UserContext::query()->where('user_id', $user->id)->where('context_type', 'staff')->firstOrFail();
    $url = '/api/hr/people/directory';

    actingAs($user, 'api')->getJson($url)->assertOk();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    $response = actingAs($user, 'api')->withHeaders($headers)->getJson($url)->assertOk();
    $rows = collect($response->json('data.data'));
    expect($rows->pluck('company_id')->unique()->all())->toBe([$secondCompany->id])
        ->and($rows->firstWhere('id', $secondStaff->id))->not->toBeNull()
        ->and($rows->firstWhere('company_id', $firstCompany->id))->toBeNull();
});
