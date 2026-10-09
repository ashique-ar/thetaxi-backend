<?php

use App\Models\Staff;
use App\Models\Company;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('stores encrypted, company-scoped records and serves uploaded documents privately', function (): void {
    [$admin, $company] = hr_seed_admin_actor();
    Storage::fake('local');
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $category = DB::table('medical_categories')->where('code', 'medical_certificate')->first();

    $response = actingAs($admin, 'api')->postJson('/api/medical-records', [
        'subject_type' => 'staff', 'subject_id' => $staff->id,
        'medical_category_id' => $category->id,
        'title' => 'Annual fitness certificate', 'description' => 'Private examination notes',
        'record_number' => 'MED-PRIVATE-001', 'issued_date' => '2026-10-01',
        'valid_until' => '2027-10-01', 'issuing_authority' => 'Clinic',
    ])->assertCreated()->assertJsonPath('data.company_id', $company->id)
        ->assertJsonPath('data.description', 'Private examination notes');
    $id = $response->json('data.id');
    expect(DB::table('medical_records')->where('id', $id)->value('description'))
        ->not->toBe('Private examination notes');

    actingAs($admin, 'api')->post('/api/medical-records/'.$id.'/upload', [
        'file' => UploadedFile::fake()->createWithContent('certificate.pdf', 'secret document bytes'),
    ])->assertCreated();
    $document = DB::table('documents')->where('documentable_id', $id)->first();
    expect($document->disk)->toBe('local')
        ->and(Storage::disk('local')->get($document->path))->not->toContain('secret document bytes');

    actingAs($admin, 'api')->get('/api/medical-records/'.$id.'/document/'.$document->id)->assertOk()
        ->assertStreamedContent('secret document bytes');
    actingAs($admin, 'api')->getJson('/api/medical-records')->assertOk()
        ->assertJsonPath('data.data.0.id', $id);
});

it('rejects cross-company records, subjects, and actors outside the default company context', function (): void {
    [$admin, $company] = hr_seed_admin_actor();
    $otherCompany = Company::create(['name' => 'Other medical tenant']);
    $foreignStaff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $category = DB::table('medical_categories')->where('code', 'medical_certificate')->first();

    actingAs($admin, 'api')->postJson('/api/medical-records', [
        'subject_type' => 'staff', 'subject_id' => $foreignStaff->id,
        'medical_category_id' => $category->id,
        'title' => 'Foreign subject', 'issued_date' => '2026-10-01',
    ])->assertNotFound();

    $homeStaff = Staff::factory()->create(['company_id' => $company->id]);
    $created = actingAs($admin, 'api')->postJson('/api/medical-records', [
        'subject_type' => 'staff', 'subject_id' => $homeStaff->id,
        'medical_category_id' => $category->id,
        'title' => 'Default company record', 'issued_date' => '2026-10-01',
    ])->assertCreated();
    $recordId = $created->json('data.id');
    actingAs($admin, 'api')->getJson('/api/medical-records/'.$recordId)->assertOk();

    $foreignActor = User::factory()->create();
    $foreignActor->assignRole('admin');
    $foreignIdentity = Staff::factory()->create([
        'user_id' => $foreignActor->id, 'company_id' => $otherCompany->id,
    ]);
    UserContext::create([
        'user_id' => $foreignActor->id, 'context_type' => 'staff', 'context_id' => $foreignIdentity->id,
        'is_active' => true, 'created_user_id' => $foreignActor->id,
    ]);
    actingAs($foreignActor, 'api')->getJson('/api/medical-records/'.$recordId)->assertForbidden();
});
