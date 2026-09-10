<?php

it('keeps the tenant decision catalogue complete, dependency-safe and fact-free', function () {
    $decisions = require config_path('tenant_decisions.php');
    $keys = array_column($decisions, 'key');

    expect($keys)->toHaveCount(count(array_unique($keys)))
        ->and($keys)->toContain(
            'company.localization', 'hr.employee_numbering', 'hr.work_calendars',
            'device.biometric_privacy', 'device.integration', 'employment.driver_relationship',
            'hr.attendance_policy', 'hr.leave_overtime_policy', 'hr.payroll_calendar_policy',
            'hr.retention_privacy', 'hr.document_governance', 'hr.approval_access',
            'hr.notification_policy', 'hr.migration_scope', 'sales.commission_policy',
            'payroll.statutory', 'integrations.approved_scope'
        );

    foreach ($decisions as $decision) {
        expect($decision['initial_template'])->toBe([])
            ->and($decision['editor_role'])->not->toBeEmpty()
            ->and($decision['approver_role'])->not->toBeEmpty();
        foreach ($decision['depends_on'] as $dependency) {
            expect($keys)->toContain($dependency)
                ->and(array_search($dependency, $keys, true))->toBeLessThan(array_search($decision['key'], $keys, true));
        }
    }
});
