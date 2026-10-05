<?php

it('returns minimal confirmations for Sales policy-setting writes that the portal reloads', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPolicySettingsController.php'));

    expect($controller)
        ->toContain(
            'private function writeConfirmation(object $row): array',
            "['id' => (string) \$row->id, 'status' => (string) \$row->status]",
            "\$confirmation['version'] = (int) \$row->version",
            '$this->writeConfirmation($setting)',
            '$this->writeConfirmation($updated)',
            '$this->writeConfirmation($this->settings->approveFeature(',
            '$this->writeConfirmation($this->settings->approveStaffCategory(',
            '$this->writeConfirmation($this->settings->retireStaffCategory(',
            '$this->writeConfirmation($category)',
        )
        ->not->toContain(
            "'data' => \$setting",
            "'data' => \$updated",
            "'data' => \$this->settings->approveFeature(",
            "'data' => \$category",
        );
});

it('renders policy rationale only to makers and approvers', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-policy-settings/sales-policy-settings.component.html'));

    expect($template)->toContain(
        "*hasPermission=\"['sales.policy-settings.manage','sales.policy-settings.approve']\"",
        '{{ draft.reason }}', '{{row.reason}}', '{{ row.reason }}', 'data-label="Rationale"',
    );
});
