<?php

use App\Models\Company;
use App\Models\Hr\HrEmployeeNumberSequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('audits employee-number sequence status and version without identifiers or allocation settings', function () {
    $company = Company::create(['name' => 'Employee-number audit company', 'is_active' => true, 'is_default' => true]);
    $actor = User::factory()->create();

    $sequence = HrEmployeeNumberSequence::create([
        'company_id' => $company->id,
        'template' => 'private-template-{NNNN}',
        'prefix' => 'private-prefix',
        'suffix' => 'private-suffix',
        'start_value' => 2000,
        'next_value' => 2001,
        'padding' => 6,
        'status' => 'active',
        'version' => 2,
        'updated_user_id' => $actor->id,
    ]);

    $properties = DB::table('activity_log')
        ->where('subject_type', HrEmployeeNumberSequence::class)
        ->where('subject_id', $sequence->id)
        ->value('attribute_changes');

    expect($properties)->not->toBeNull()
        ->and($properties)->toContain('active')
        ->and($properties)->toContain('2')
        ->and($properties)->not->toContain((string) $company->id)
        ->and($properties)->not->toContain((string) $actor->id)
        ->and($properties)->not->toContain('private-template')
        ->and($properties)->not->toContain('private-prefix')
        ->and($properties)->not->toContain('private-suffix')
        ->and($properties)->not->toContain('2000')
        ->and($properties)->not->toContain('2001')
        ->and($properties)->not->toContain('padding');
});
