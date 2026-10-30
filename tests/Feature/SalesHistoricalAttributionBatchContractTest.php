<?php

it('applies only a checksum-bound installation-wide historical attribution preview',function(){
 $controller=file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));$routes=file_get_contents(base_path('routes/api.php'));
 expect($routes)->toContain("attributions/historical-batch")->toContain("permission:sales.attributions.correct")
  ->and($controller)->toContain("'preview_checksum'=>\$checksum")->toContain("hash_equals(\$checksum,\$data['preview_checksum'])")
  ->toContain("whereDoesntHave('salesAttribution')")->toContain("where('idempotency_key',\$data['idempotency_key'])")
  ->not->toContain("'company_id'=>\$data['company_id']")
  ->toContain('captureConfirmation($booking)')
  ->toContain("'remaining_confirmed_without_attribution'")->toContain("'idempotent_replay'=>true")
  ->toContain('Historical attribution batch key was reused with different evidence.');
});

it('keeps historical attribution owner review independent from locations', function () {
 $attribution=file_get_contents(app_path('Services/Sales/BookingAttributionService.php'));
 $page=file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

 expect($attribution)->toContain("'company_id' => \$booking->company_id")
  ->not->toContain("'legal_entity_missing' =>")
  ->not->toContain('SingleCompanyScope')
  ->not->toContain('defaultCompanyId')
  ->toContain("'booking_label' => \$booking->booking_number")
  ->and($page)->toContain('row.booking_label', 'Check each suggested salesperson')
  ->not->toContain('<h3>{{ row.booking_id }}</h3>');
});
