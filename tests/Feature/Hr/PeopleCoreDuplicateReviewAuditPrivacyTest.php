<?php

use App\Models\Company;
use App\Models\Hr\HrPeopleDuplicateReview;
use App\Models\Hr\HrPeopleIdentityLink;
use App\Models\Staff;
use App\Models\User;
use App\Services\Hr\PeopleCoreMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps duplicate-review identity evidence immutable and decision reasons out of activity logs', function () {
    $preparer = User::factory()->create();
    $checker = User::factory()->create();
    $company = Company::create(['name' => 'Duplicate review privacy company', 'is_active' => true, 'is_default' => true]);
    $first = Staff::factory()->create(['company_id' => $company->id, 'code' => 'PRIVACY-001']);
    $second = Staff::factory()->create(['company_id' => $company->id, 'code' => 'PRIVACY-002']);
    $secret = 'duplicate-review-private-fingerprint';
    DB::table('staff')->whereIn('id', [$first->id, $second->id])->update(['nic_fingerprint' => $secret]);
    $reason = 'duplicate-review-private-reason';
    actingAs($preparer, 'api');

    $service = app(PeopleCoreMigrationService::class);
    $service->detectDuplicates($company->id, $preparer->id);
    $review = HrPeopleDuplicateReview::query()->where('company_id', $company->id)->sole();
    actingAs($checker, 'api');
    $service->decideDuplicate($review, $company->id, [
        'expected_version' => 1,
        'disposition' => 'canonical_selected',
        'canonical_staff_id' => $first->id,
        'reason' => $reason,
    ], $checker->id);
    $review = $review->fresh();
    $service->consolidateDuplicate($review, $company->id, $review->version, $checker->id);

    $reviewProperties = DB::table('activity_log')
        ->where('subject_type', HrPeopleDuplicateReview::class)
        ->where('subject_id', $review->id)
        ->pluck('attribute_changes');
    $link = HrPeopleIdentityLink::query()->where('alias_staff_id', $second->id)->sole();
    expect(fn () => $link->update(['status' => 'revoked']))
        ->toThrow(LogicException::class, 'People identity links are immutable.')
        ->and(fn () => $link->delete())
        ->toThrow(LogicException::class, 'People identity links cannot be deleted.');
    $this->assertDatabaseHas('hr_people_identity_links', [
        'id' => $link->id,
        'status' => 'active',
        'deleted_at' => null,
    ]);
    $linkProperties = DB::table('activity_log')
        ->where('subject_type', HrPeopleIdentityLink::class)
        ->where('subject_id', $link->id)
        ->pluck('attribute_changes');
    $properties = $reviewProperties->concat($linkProperties);
    $audit = $properties->implode(' ');

    expect($reviewProperties)->not->toBeEmpty()
        ->and($linkProperties)->not->toBeEmpty()
        ->and($audit)->toContain((string) $company->id)
        ->and($audit)->toContain('canonical_selected')
        ->and($audit)->toContain('active')
        ->and($audit)->not->toContain((string) $first->id)
        ->and($audit)->not->toContain((string) $second->id)
        ->and($audit)->not->toContain(hash('sha256', 'nic_fingerprint|'.$secret))
        ->and($audit)->not->toContain('nic_fingerprint')
        ->and($audit)->not->toContain($reason)
        ->and($audit)->not->toContain('safe_candidate_snapshot');
});

it('requires an active company for duplicate-review decisions and consolidation', function () {
    $preparer = User::factory()->create();
    $actor = User::factory()->create();
    $company = Company::create(['name' => 'Inactive duplicate review company', 'is_active' => false]);
    $review = HrPeopleDuplicateReview::create([
        'company_id' => $company->id,
        'match_kind' => 'code',
        'match_fingerprint' => hash('sha256', 'inactive-review'),
        'candidate_staff_ids' => [],
        'safe_candidate_snapshot' => [],
        'prepared_by' => $preparer->id,
    ]);
    $service = app(PeopleCoreMigrationService::class);

    expect(fn () => $service->decideDuplicate($review, (string) $company->id, [
        'expected_version' => 1,
        'disposition' => 'keep_separate',
        'reason' => 'Reviewed while company is inactive.',
    ], (string) $actor->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class)
        ->and(fn () => $service->consolidateDuplicate($review, (string) $company->id, 1, (string) $actor->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
