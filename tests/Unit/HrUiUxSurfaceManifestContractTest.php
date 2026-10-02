<?php

use PHPUnit\Framework\TestCase;

final class HrUiUxSurfaceManifestContractTest extends TestCase
{
    public function test_manifest_covers_every_registered_hr_frontend_and_backend_owner(): void
    {
        $workspace = dirname(__DIR__, 3);
        $manifest = file_get_contents($workspace.'/HR_UI_UX_SURFACE_MANIFEST.md');
        $appRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/app.routes.ts');
        $apiRoutes = file_get_contents($workspace.'/public-thetaxi/routes/api.php');

        $this->assertNotFalse($manifest);

        foreach (['hr-attendance', 'hr-people', 'hr-workforce', 'hr-self-service', 'hr-lifecycle', 'hr-talent', 'hr-relations', 'hr-engagement'] as $module) {
            $this->assertStringContainsString("modules/{$module}", $appRoutes, "Missing {$module} route owner");
            $this->assertStringContainsString($module, $manifest, "Manifest omits {$module}");
        }

        foreach (['hr/people', 'hr/employees', 'hr/organization', 'hr/attendance', 'hr/workforce', 'hr/payroll', 'hr/ess', 'hr/recruitment', 'hr/lifecycle', 'hr/performance', 'hr/learning', 'hr/service-operations', 'hr/assets', 'hr/travel', 'hr/knowledge', 'hr/talent', 'hr/meals', 'hr/integration-deliveries', 'hr/relations', 'hr/safety', 'hr/engagement', 'hr/analytics', 'hr/notifications'] as $prefix) {
            $this->assertStringContainsString("Route::prefix('{$prefix}')", $apiRoutes, "Missing {$prefix} backend owner");
            $this->assertStringContainsString("`{$prefix}`", $manifest, "Manifest omits {$prefix}");
        }

        $this->assertStringContainsString("Route::get('hr/governance/queues'", $apiRoutes);
        $this->assertStringContainsString('`hr/governance`', $manifest);
        $this->assertStringContainsString("Route::post('hr/attendance/ingest'", $apiRoutes);
        $this->assertStringContainsString('`hr/attendance/ingest`', $manifest);
    }

    public function test_phase_one_has_addressable_people_workspaces_and_compatibility_redirects(): void
    {
        $workspace = dirname(__DIR__, 3);
        $peopleRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-people/hr-people.routes.ts');
        $appRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/app.routes.ts');
        $staffRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/staff/staff.routes.ts');

        foreach (['summary', 'employment', 'documents', 'timeline'] as $section) {
            $this->assertStringContainsString("['{$section}', '{$section}']", $peopleRoutes);
        }
        $this->assertStringContainsString("redirectTo: ':staffId/summary'", $peopleRoutes);

        foreach (['structure', 'custom-fields', 'work-calendars', 'document-types', 'jobs', 'reporting', 'acting-appointments'] as $section) {
            $this->assertStringContainsString("path: '{$section}'", $appRoutes);
        }
        $this->assertStringContainsString("redirectTo: 'structure'", $appRoutes);
        $this->assertStringContainsString("path: ':id/edit'", $staffRoutes);
    }

    public function test_phase_two_has_addressable_attendance_workspaces_and_daily_mapping_default(): void
    {
        $workspace = dirname(__DIR__, 3);
        $routes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-attendance/hr-attendance.routes.ts');
        $shell = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-attendance/components/attendance-operations-shell/attendance-operations-shell.component.ts');
        $configuration = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-attendance/components/attendance-configuration/attendance-configuration.component.html');

        $this->assertStringContainsString("redirectTo: 'people'", $routes);
        foreach (['overview', 'people', 'devices', 'access', 'exceptions'] as $section) {
            $this->assertStringContainsString("path: '{$section}'", $routes);
        }
        $this->assertStringContainsString("['calendars', 'shifts', 'policies', 'rosters']", $routes);
        $this->assertStringContainsString("label: 'Map Staff'", $shell);
        $this->assertStringNotContainsString('[hidden]="activeSection()', $configuration);
    }

    public function test_phase_three_uses_addressable_workforce_and_scoped_self_service_workspaces(): void
    {
        $workspace = dirname(__DIR__, 3);
        $routes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-workforce/hr-workforce.routes.ts');
        $overview = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-workforce/components/workforce-overview/workforce-overview.component.html');
        $leave = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-workforce/components/leave-configuration/leave-configuration.component.html');
        $payroll = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-workforce/components/payroll-statutory-configuration/payroll-statutory-configuration.component.html');
        $selfServiceRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-self-service/hr-self-service.routes.ts');
        $selfServiceShell = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-self-service/components/self-service-shell/self-service-shell.component.ts');

        foreach (['leave', 'work-requests', 'timesheets'] as $section) {
            $this->assertStringContainsString("'{$section}'", $routes);
        }
        foreach (['types', 'policies', 'assignments'] as $section) {
            $this->assertStringContainsString("'{$section}'", $routes);
        }
        foreach (['epf-etf', 'gratuity'] as $section) {
            $this->assertStringContainsString("'{$section}'", $routes);
        }
        $this->assertStringNotContainsString('<mat-tab-group', $overview.$leave.$payroll);
        $this->assertStringContainsString('<router-outlet></router-outlet>', $selfServiceShell);
        foreach (['requests', 'panel-interviews', 'approvals'] as $section) {
            $this->assertStringContainsString("path: '{$section}'", $selfServiceRoutes);
        }
        foreach (['Self scope', 'Assigned to me', 'My permitted team scope'] as $scope) {
            $this->assertStringContainsString($scope, $selfServiceShell);
        }
    }

    public function test_phase_four_has_addressable_recruitment_lifecycle_and_talent_workspaces(): void
    {
        $workspace = dirname(__DIR__, 3);
        $lifecycleRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-lifecycle/hr-lifecycle.routes.ts');
        $talentRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-talent/hr-talent.routes.ts');

        foreach (['requisitions', 'candidates', 'applications', 'analytics'] as $section) {
            $this->assertStringContainsString("'{$section}'", $lifecycleRoutes);
        }
        foreach (['active', 'templates'] as $section) {
            $this->assertStringContainsString("'{$section}'", $lifecycleRoutes);
        }
        foreach (['catalogue', 'my-compliance', 'requirements', 'expenses', 'service-desk', 'assets', 'phones', 'travel'] as $section) {
            $this->assertStringContainsString("'{$section}'", $talentRoutes);
        }

        $templates = [
            'hr-lifecycle/components/recruitment/recruitment.component.html',
            'hr-lifecycle/components/lifecycle-cases/lifecycle-cases.component.html',
            'hr-talent/components/learning/learning.component.html',
            'hr-talent/components/service-operations/service-operations.component.html',
            'hr-talent/components/assets-travel/assets-travel.component.html',
        ];
        foreach ($templates as $template) {
            $source = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/'.$template);
            $this->assertStringNotContainsString('<mat-tab-group', $source);
            $this->assertStringContainsString('routerLink=', $source);
        }
    }

    public function test_phase_five_has_addressable_relations_engagement_and_administration_workspaces(): void
    {
        $workspace = dirname(__DIR__, 3);
        $relationsRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-relations/hr-relations.routes.ts');
        $engagementRoutes = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-engagement/hr-engagement.routes.ts');

        foreach (['cases', 'safety', 'safety/report', 'safety/registers', 'safety/:id'] as $section) {
            $this->assertStringContainsString("path: '{$section}'", $relationsRoutes);
        }
        foreach (['announcements', 'surveys', 'recognition', 'wellness', 'analytics', 'reporting', 'data-quality', 'notification-preferences', 'governance', 'administration'] as $section) {
            $this->assertStringContainsString("path: '{$section}'", $engagementRoutes);
        }
        foreach (['metrics', 'workforce-plans', 'report-views', 'schedules', 'notification-templates'] as $section) {
            $this->assertStringContainsString("'{$section}'", $engagementRoutes);
        }

        foreach (['analytics-administration/analytics-administration.component.html', 'delivery-administration/delivery-administration.component.html'] as $template) {
            $source = file_get_contents($workspace.'/portal-thetaxi/src/app/modules/hr-engagement/components/'.$template);
            $this->assertStringNotContainsString('<mat-tab-group', $source);
            $this->assertStringContainsString('routerLink=', $source);
        }
        $this->assertLessThan(strpos($relationsRoutes, "path: 'safety/:id'"), strpos($relationsRoutes, "path: 'safety/report'"));
        $this->assertLessThan(strpos($relationsRoutes, "path: 'safety/:id'"), strpos($relationsRoutes, "path: 'safety/registers'"));
    }
}
