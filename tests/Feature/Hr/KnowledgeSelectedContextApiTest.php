<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes published Knowledge and acknowledgements to the selected Staff context', function () {
    [$admin, $firstCompany] = hr_seed_admin_actor();
    $admin->givePermissionTo('hr.knowledge.view');
    config(['hr.features.knowledge' => true]);
    $secondCompany = Company::create(['name' => 'Second Knowledge company']);
    $secondStaff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $secondStaff->update(['company_id' => $secondCompany->id]);
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')->firstOrFail();
    $articleIds = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ([[$articleIds[0], $firstCompany->id, 'FIRST'], [$articleIds[1], $secondCompany->id, 'SECOND']] as [$id, $companyId, $slug]) {
        DB::table('hr_knowledge_articles')->insert([
            'id' => $id, 'company_id' => $companyId, 'slug' => $slug, 'title' => $slug.' article',
            'body' => 'Published knowledge', 'audience' => json_encode(['all' => true], JSON_THROW_ON_ERROR),
            'version' => 1, 'effective_from' => today()->toDateString(), 'status' => 'published',
            'content_checksum' => hash('sha256', $slug.' article'),
            'acknowledgement_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    actingAs($admin, 'api')->getJson('/api/hr/knowledge/articles')->assertOk();
    $headers = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id];
    actingAs($admin, 'api')->withHeaders($headers)->getJson('/api/hr/knowledge/articles')
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $articleIds[1]);
    actingAs($admin, 'api')->withHeaders($headers)->postJson('/api/hr/knowledge/articles/'.$articleIds[1].'/acknowledge')
        ->assertOk();
    expect(DB::table('hr_knowledge_acknowledgements')->where('article_id', $articleIds[1])->value('staff_id'))
        ->toBe($secondStaff->id)
        ->and(DB::table('hr_knowledge_acknowledgements')->where('article_id', $articleIds[0])->exists())->toBeFalse();
});
