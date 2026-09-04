<?php

use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('previews and commits one clean tenant legacy employment row then reconciles it', function () {
    [$admin,$company]=hr_seed_admin_actor();config(['hr.features.people_core'=>true]);Storage::fake('hr_private');
    $staff=Staff::factory()->create(['company_id'=>$company->id,'code'=>'LEG-001','employment_ended_at'=>null]);
    $csv="employee_number,joined_at,service_date,confirmation_date\nLEG-001,2020-01-02,2020-01-02,2020-07-02\n";
    $preview=actingAs($admin,'api')->post('/api/hr/people/imports/preview',['file'=>UploadedFile::fake()->createWithContent('people.csv',$csv),'idempotency_key'=>'preview-clean-1']);
    $preview->assertCreated()->assertJsonPath('data.job.accepted_count',1)->assertJsonPath('data.job.rejected_count',0)->assertJsonPath('data.rows.0.outcome','accepted');
    $jobId=$preview->json('data.job.id');$checksum=$preview->json('data.job.file_checksum');
    actingAs($admin,'api')->postJson("/api/hr/people/imports/{$jobId}/commit",['expected_file_checksum'=>$checksum])->assertOk()->assertJsonPath('data.job.status','committed');
    $spell=HrEmploymentSpell::where('staff_id',$staff->id)->sole();
    expect($spell->joined_at->toDateString())->toBe('2020-01-02')->and($spell->prior_service_decisions['legacy_import'])->toBeTrue();
    $before=(int)$preview->json('data.job.reconciliation_totals.before.issues.activeMissingSpell');
    actingAs($admin,'api')->getJson('/api/hr/people/reconciliation')->assertOk()->assertJsonPath('data.issues.activeMissingSpell',$before-1);
});

it('retains ambiguous and invalid legacy rows as rejected evidence and refuses commit', function () {
    [$admin,$company]=hr_seed_admin_actor();config(['hr.features.people_core'=>true]);Storage::fake('hr_private');
    Staff::factory()->create(['company_id'=>$company->id,'code'=>'LEG-002']);
    $csv="employee_number,joined_at,service_date,confirmation_date\nLEG-002,not-a-date,2021-01-01,\n";
    $preview=actingAs($admin,'api')->post('/api/hr/people/imports/preview',['file'=>UploadedFile::fake()->createWithContent('invalid.csv',$csv),'idempotency_key'=>'preview-rejected-1']);
    $preview->assertCreated()->assertJsonPath('data.job.rejected_count',1)->assertJsonPath('data.rows.0.outcome','rejected')->assertJsonPath('data.rows.0.errors.0','joined_at must be YYYY-MM-DD.');
    actingAs($admin,'api')->postJson('/api/hr/people/imports/'.$preview->json('data.job.id').'/commit',['expected_file_checksum'=>$preview->json('data.job.file_checksum')])->assertStatus(409);
});

it('generates and downloads an integrity checked private tenant export', function () {
    [$admin,$company]=hr_seed_admin_actor();config(['hr.features.people_core'=>true]);Storage::fake('hr_private');
    Staff::factory()->create(['company_id'=>$company->id,'code'=>'EXP-001']);
    $created=actingAs($admin,'api')->postJson('/api/hr/people/exports',['idempotency_key'=>'export-1'])->assertCreated();
    $id=$created->json('data.id');Storage::disk('hr_private')->assertExists($created->json('data.path'));
    actingAs($admin,'api')->get("/api/hr/people/exports/{$id}/download")->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');
});

it('resolves readable assignment references inside the tenant and creates the initial assignment', function () {
    [$admin,$company]=hr_seed_admin_actor();config(['hr.features.people_core'=>true]);Storage::fake('hr_private');
    $employee=Staff::factory()->create(['company_id'=>$company->id,'code'=>'LEG-ASSIGN','employment_ended_at'=>null,'deleted_at'=>null]);
    $unit=(string)Str::uuid();$designation=(string)Str::uuid();$position=(string)Str::uuid();$type=(string)Str::uuid();
    DB::table('hr_organization_units')->insert(['id'=>$unit,'company_id'=>$company->id,'unit_type'=>'department','code'=>'OPS','name'=>'Operations','timezone'=>'Asia/Colombo','status'=>'active','effective_from'=>'2019-01-01','created_user_id'=>$admin->id,'created_at'=>now(),'updated_at'=>now()]);
    DB::table('hr_designations')->insert(['id'=>$designation,'company_id'=>$company->id,'code'=>'OFFICER','name'=>'Officer','status'=>'active','version'=>1,'effective_from'=>'2019-01-01','created_at'=>now(),'updated_at'=>now()]);
    DB::table('hr_positions')->insert(['id'=>$position,'company_id'=>$company->id,'organization_unit_id'=>$unit,'designation_id'=>$designation,'position_number'=>'POS-001','title'=>'Operations Officer','headcount_limit'=>2,'status'=>'active','effective_from'=>'2019-01-01','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
    DB::table('hr_employment_types')->insert(['id'=>$type,'company_id'=>$company->id,'code'=>'PERM','name'=>'Permanent','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
    $header='employee_number,joined_at,service_date,confirmation_date,employment_type_code,organization_unit_code,position_number,location_code';
    $csv=$header."\nLEG-ASSIGN,2020-01-02,2020-01-02,2020-07-02,PERM,OPS,POS-001,Colombo\n";
    $preview=actingAs($admin,'api')->post('/api/hr/people/imports/preview',['file'=>UploadedFile::fake()->createWithContent('assignment.csv',$csv),'idempotency_key'=>'assignment-preview']);
    $preview->assertCreated()->assertJsonPath('data.job.rejected_count',0);
    $response=actingAs($admin,'api')->postJson('/api/hr/people/imports/'.$preview->json('data.job.id').'/commit',['expected_file_checksum'=>$preview->json('data.job.file_checksum')]);
    $response->assertOk();
    $spell=HrEmploymentSpell::where('staff_id',$employee->id)->sole();$assignment=DB::table('hr_employment_assignments')->where('staff_id',$employee->id)->first();
    expect($spell->employment_type_id)->toBe($type)->and($assignment->organization_unit_id)->toBe($unit)->and($assignment->position_id)->toBe($position)->and($assignment->location_code)->toBe('Colombo');
});

it('detects duplicate identity evidence and requires a different authorized checker', function () {
    [$maker,$company]=hr_seed_admin_actor();config(['hr.features.people_core'=>true]);
    $first=Staff::factory()->create(['company_id'=>$company->id,'code'=>'DUP-001']);
    $second=Staff::factory()->create(['company_id'=>$company->id,'code'=>'DUP-002']);
    $fingerprint=hash('sha256','same-reviewed-nic');
    DB::table('staff')->whereIn('id',[$first->id,$second->id])->update(['nic_fingerprint'=>$fingerprint]);

    actingAs($maker,'api')->postJson('/api/hr/people/duplicate-reviews/detect')->assertOk()
        ->assertJsonPath('data.created',1)->assertJsonPath('data.pending',1);
    actingAs($maker,'api')->postJson('/api/hr/people/duplicate-reviews/detect')->assertOk()
        ->assertJsonPath('data.created',0)->assertJsonPath('data.pending',1);
    $review=actingAs($maker,'api')->getJson('/api/hr/people/duplicate-reviews')->assertOk()->json('data.data.0');
    expect($review['match_kind'])->toBe('nic_fingerprint')->and($review['safe_candidate_snapshot'][0])->not->toHaveKey('nic');
    actingAs($maker,'api')->postJson('/api/hr/people/duplicate-reviews/'.$review['id'].'/decide',[
        'expected_version'=>$review['version'],'disposition'=>'canonical_selected','canonical_staff_id'=>$first->id,'reason'=>'Reviewed as one legacy identity.',
    ])->assertForbidden();

    $checker=User::factory()->create();$checker->assignRole('admin');$checkerStaff=Staff::factory()->create(['user_id'=>$checker->id,'company_id'=>$company->id,'code'=>'CHECKER-001']);
    UserContext::create(['user_id'=>$checker->id,'context_type'=>'staff','context_id'=>$checkerStaff->id,'is_active'=>true,'created_user_id'=>$checker->id]);
    $decision=actingAs($checker,'api')->postJson('/api/hr/people/duplicate-reviews/'.$review['id'].'/decide',[
        'expected_version'=>$review['version'],'disposition'=>'canonical_selected','canonical_staff_id'=>$first->id,'reason'=>'Reviewed as one legacy identity.',
    ])->assertOk()->assertJsonPath('data.status','decided')->assertJsonPath('data.canonical_staff_id',$first->id);
    $version=$decision->json('data.version');
    actingAs($checker,'api')->postJson('/api/hr/people/duplicate-reviews/'.$review['id'].'/consolidate',['expected_version'=>$version])
        ->assertOk()->assertJsonPath('data.consolidation_status','linked')->assertJsonPath('data.consolidation_snapshot.historical_references_rewritten',false)->assertJsonPath('data.consolidation_snapshot.link_count',1);
    actingAs($checker,'api')->postJson('/api/hr/people/duplicate-reviews/'.$review['id'].'/consolidate',['expected_version'=>$version])
        ->assertOk()->assertJsonPath('data.consolidation_status','linked');
    expect(DB::table('hr_people_identity_links')->where('alias_staff_id',$second->id)->where('canonical_staff_id',$first->id)->count())->toBe(1)
        ->and(app(\App\Services\Hr\CanonicalStaffResolver::class)->id($second->id,$company->id))->toBe($first->id)
        ->and(app(\App\Services\Hr\CanonicalStaffResolver::class)->id($first->id,$company->id))->toBe($first->id);
    expect(Staff::withTrashed()->whereIn('id',[$first->id,$second->id])->count())->toBe(2);
});

it('does not expose or decide another legal entity duplicate review', function () {
    [$actor]=hr_seed_admin_actor();config(['hr.features.people_core'=>true]);
    $other=Company::create(['name'=>'Other duplicate tenant']);$one=Staff::factory()->create(['company_id'=>$other->id,'code'=>'OTHER-DUP-1']);$two=Staff::factory()->create(['company_id'=>$other->id,'code'=>'OTHER-DUP-2']);
    $fingerprint=hash('sha256','other-tenant-nic');DB::table('staff')->whereIn('id',[$one->id,$two->id])->update(['nic_fingerprint'=>$fingerprint]);
    $service=app(\App\Services\Hr\PeopleCoreMigrationService::class);$service->detectDuplicates($other->id,$actor->id);
    $review=\App\Models\Hr\HrPeopleDuplicateReview::where('company_id',$other->id)->sole();
    $rows=actingAs($actor,'api')->getJson('/api/hr/people/duplicate-reviews')->assertOk()->json('data.data');expect($rows)->toBe([]);
    actingAs($actor,'api')->postJson('/api/hr/people/duplicate-reviews/'.$review->id.'/decide',['expected_version'=>1,'disposition'=>'keep_separate','reason'=>'Cross tenant denial proof.'])->assertNotFound();
});
