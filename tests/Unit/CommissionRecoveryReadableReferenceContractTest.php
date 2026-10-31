<?php

it('resolves recovery beneficiaries from the installation-wide Sales profile and hides raw IDs in the view', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionRecoveryController.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesCommissionRecoveryCase.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-financial-corrections/sales-financial-corrections.component.html'));

    expect($model)->toContain("belongsTo(SalesProfile::class, 'beneficiary_sales_profile_id')->withTrashed()")
        ->and($controller)->toContain("if (\$profileIds !== null) \$query->whereIn('beneficiary_sales_profile_id', \$profileIds);", 'beneficiarySalesProfile:id,sales_code', 'beneficiary_sales_code')
        ->and($template)->toContain('recalculated_tier_sequence', 'beneficiary_sales_code')
        ->not->toContain('recalculated_plan_tier_id', 'beneficiary_staff_id');
});
