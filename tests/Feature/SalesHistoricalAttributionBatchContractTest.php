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

it('uses only the sole default company for a booking without company evidence and shows review labels', function () {
 $attribution=file_get_contents(app_path('Services/Sales/BookingAttributionService.php'));
 $page=file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-attribution-operations/sales-attribution-operations.component.html'));

 expect($attribution)->toContain("app(SingleCompanyScope::class)->defaultCompany()?->id")
  ->toContain("'booking_label' => \$booking->booking_number")
  ->and($page)->toContain('row.booking_label', 'Check each suggested salesperson')
  ->not->toContain('<h3>{{ row.booking_id }}</h3>');
});
