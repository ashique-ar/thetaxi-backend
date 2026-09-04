<?php
it('detects tenant duplicate signals and records a maker checker decision without mutating Staff',function(){
 $service=file_get_contents(app_path('Services/Hr/PeopleCoreMigrationService.php'));$routes=file_get_contents(base_path('routes/api.php'));$migration=file_get_contents(database_path('migrations/2026_09_04_121000_create_hr_people_duplicate_reviews.php'));
 expect($routes)->toContain("duplicate-reviews/detect")->toContain("duplicate-reviews/{review}/decide")->toContain("duplicate-reviews/{review}/consolidate")
  ->and($service)->toContain("['user_id','code','nic_fingerprint','license_no_fingerprint']")
  ->toContain("'safe_candidate_snapshot'")
  ->toContain('The duplicate-review preparer cannot decide the same case.')
  ->toContain("in_array(\$canonical,\$review->candidate_staff_ids,true)")
  ->toContain("where('company_id',\$companyId)->lockForUpdate()")
  ->and($migration)->toContain("json('candidate_staff_ids')")
  ->toContain("json('safe_candidate_snapshot')")
  ->toContain('Rollback refused: export and reconcile retained People Core duplicate-review decisions first.');
});

it('uses canonical identity links instead of rewriting historical domain ownership',function(){
 $service=file_get_contents(app_path('Services/Hr/PeopleCoreMigrationService.php'));$resolver=file_get_contents(app_path('Services/Hr/CanonicalStaffResolver.php'));$migration=file_get_contents(database_path('migrations/2026_09_04_122000_create_hr_people_identity_links.php'));
 expect($service)->toContain("'strategy'=>'canonical_link_v1'")->toContain("'historical_references_rewritten'=>false")->toContain("where('alias_staff_id',\$aliasId)->lockForUpdate()")
  ->toContain('The selected canonical Staff is already an alias of another identity.')
  ->toContain('A reviewed alias is already canonical for another identity.')
  ->and($resolver)->toContain("where('company_id', \$companyId)")->toContain("where('status', 'active')")
  ->and($migration)->toContain("foreignUuid('alias_staff_id')->unique()")
  ->toContain('Rollback refused: revoke and export canonical identity links first.');
});

it('exposes only explicit non destructive dispositions',function(){
 $controller=file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
 expect($controller)->toContain("Rule::in(['keep_separate','canonical_selected','false_positive'])")
  ->toContain("'reason'=>['required','string','min:10','max:2000']")
  ->not->toContain('mergeStaff');
});
