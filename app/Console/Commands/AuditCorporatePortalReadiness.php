<?php

namespace App\Console\Commands;

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use Illuminate\Console\Command;

class AuditCorporatePortalReadiness extends Command
{
    protected $signature = 'corporate:portal-readiness {--json : Print machine-readable output}';

    protected $description = 'Read-only audit of existing corporate accounts and portal user contexts';

    public function handle(): int
    {
        $rows = Corporate::query()
            ->withCount(['departments', 'employees', 'serviceTypes', 'vehicleGroups', 'billingTerms'])
            ->orderBy('name')
            ->get()
            ->map(function (Corporate $corporate): array {
                $employees = CorporateEmployee::where('corporate_id', $corporate->id);
                $missingContext = (clone $employees)->whereDoesntHave('userContext')->count();
                $missingRole = (clone $employees)->whereHas('userContext')
                    ->whereDoesntHave('userContext.roles')->count();
                $inactiveContexts = (clone $employees)->whereHas('userContext', fn ($query) => $query->where('is_active', false))->count();
                $administrators = (clone $employees)->whereHas('userContext.roles', fn ($query) => $query->where('name', 'Corporate_Master_Admin'))->count();
                $globalCorporateRoles = (clone $employees)->whereHas('user.roles', fn ($query) => $query->whereIn('name', [
                    'Corporate_Master_Admin', 'Transport_Coordinator', 'Approval_Manager', 'Corporate_Employee',
                ]))->count();
                $inactiveUsers = (clone $employees)->where('is_active', false)->count();
                $missingUsers = (clone $employees)->whereDoesntHave('user')->count();
                $inactiveLogins = (clone $employees)->whereHas('user', fn ($query) => $query->where('is_active', false))->count();

                return [
                    'id' => $corporate->id,
                    'company' => $corporate->name,
                    'active' => (bool) $corporate->is_active,
                    'departments' => $corporate->departments_count,
                    'employees' => $corporate->employees_count,
                    'inactive_employees' => $inactiveUsers,
                    'missing_users' => $missingUsers,
                    'inactive_logins' => $inactiveLogins,
                    'inactive_portal_contexts' => $inactiveContexts,
                    'portal_administrators' => $administrators,
                    'employees_with_global_corporate_role' => $globalCorporateRoles,
                    'missing_portal_context' => $missingContext,
                    'missing_context_role' => $missingRole,
                    'services' => $corporate->service_types_count,
                    'vehicle_groups' => $corporate->vehicle_groups_count,
                    'billing_terms' => $corporate->billing_terms_count,
                ];
            })->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Company', 'Active', 'Departments', 'Employees', 'Inactive employees', 'Missing users', 'Inactive logins', 'Inactive contexts', 'Admins', 'Global roles', 'No context', 'No role', 'Services', 'Vehicle groups', 'Billing terms'],
                array_map(fn (array $row) => [
                    $row['company'], $row['active'] ? 'yes' : 'no', $row['departments'], $row['employees'],
                    $row['inactive_employees'], $row['missing_users'], $row['inactive_logins'],
                    $row['inactive_portal_contexts'], $row['portal_administrators'], $row['employees_with_global_corporate_role'],
                    $row['missing_portal_context'], $row['missing_context_role'],
                    $row['services'], $row['vehicle_groups'], $row['billing_terms'],
                ], $rows)
            );
        }

        return self::SUCCESS;
    }
}
