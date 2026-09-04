<?php

it('applies only a checksum bound scoped historical attribution preview',function(){
 $controller=file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));$routes=file_get_contents(base_path('routes/api.php'));
 expect($routes)->toContain("attributions/historical-batch")->toContain("permission:sales.attributions.correct")
  ->and($controller)->toContain("'preview_checksum'=>\$checksum")->toContain("hash_equals(\$checksum,\$data['preview_checksum'])")
  ->toContain("whereDoesntHave('salesAttribution')")->toContain("DB::table('companies')->where('id',\$data['company_id'])->lockForUpdate()")
  ->toContain('captureConfirmation($booking)')
  ->toContain("'remaining_confirmed_without_attribution'")->toContain("'idempotent_replay'=>true")
  ->toContain('Historical attribution batch key was reused with different evidence.');
});
