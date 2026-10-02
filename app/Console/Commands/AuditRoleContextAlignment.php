<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserContext;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class AuditRoleContextAlignment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contexts:audit 
                            {--fix : Automatically fix mismatches}
                            {--detailed : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and optionally fix role-context alignment issues';

    /**
     * Role to context mapping
     */
    private array $roleContextMap = [
        'driver' => 'driver',
        'customer' => 'customer',
        'agent' => 'agent',
        'staff' => 'staff',
        'vehicle-owner' => 'vehicle_owner',  // Note: role uses dash, context uses underscore
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fix = $this->option('fix');
        $detailed = $this->option('detailed');

        $this->info('Starting role-context alignment audit...');
        $this->newLine();

        $users = User::with(['roles', 'contexts'])->get();
        
        $this->info("Auditing {$users->count()} users");
        $this->newLine();

        $issues = [
            'role_without_context' => [],
            'context_without_role' => [],
            'inactive_context_with_role' => [],
        ];

        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        foreach ($users as $user) {
            $userRoles = $user->roles->pluck('name')->toArray();
            $userContexts = $user->contexts()
                ->where('is_active', true)
                ->get()
                ->pluck('context_type')
                ->toArray();

            // Check for roles without corresponding contexts
            foreach ($userRoles as $roleName) {
                if (isset($this->roleContextMap[$roleName])) {
                    $expectedContext = $this->roleContextMap[$roleName];
                    if (!in_array($expectedContext, $userContexts)) {
                        $issues['role_without_context'][] = [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'role' => $roleName,
                            'expected_context' => $expectedContext,
                        ];

                        if ($fix) {
                            $this->fixRoleWithoutContext($user, $roleName, $expectedContext);
                        }
                    }
                }

                // Note: vehicle-owner with dash is the correct role name, not legacy
            }

            // Check for contexts without corresponding roles
            foreach ($userContexts as $contextType) {
                $expectedRole = $this->getExpectedRole($contextType);
                if ($expectedRole && !in_array($expectedRole, $userRoles)) {
                    $issues['context_without_role'][] = [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'context' => $contextType,
                        'expected_role' => $expectedRole,
                    ];

                    if ($fix) {
                        $this->fixContextWithoutRole($user, $contextType, $expectedRole);
                    }
                }
            }

            // Check for inactive contexts with active roles
            $inactiveContexts = $user->contexts()
                ->where('is_active', false)
                ->get();

            foreach ($inactiveContexts as $context) {
                $expectedRole = $this->getExpectedRole($context->context_type);
                if ($expectedRole && in_array($expectedRole, $userRoles)) {
                    $issues['inactive_context_with_role'][] = [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'context' => $context->context_type,
                        'role' => $expectedRole,
                    ];

                    if ($fix) {
                        $this->fixInactiveContextWithRole($user, $expectedRole);
                    }
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->displayResults($issues, $detailed);

        if (!$fix && $this->hasIssues($issues)) {
            $this->newLine();
            $this->warn('Issues found! Run with --fix to automatically correct them.');
        }

        return Command::SUCCESS;
    }

    /**
     * Display audit results
     */
    private function displayResults(array $issues, bool $detailed): void
    {
        $this->info('=== Audit Results ===');
        $this->newLine();

        // Summary table
        $this->table(
            ['Issue Type', 'Count'],
            [
                ['Roles without contexts', count($issues['role_without_context'])],
                ['Contexts without roles', count($issues['context_without_role'])],
                ['Inactive contexts with roles', count($issues['inactive_context_with_role'])],
            ]
        );

        if ($detailed) {
            $this->newLine();
            $this->displayDetailedIssues($issues);
        }
    }

    /**
     * Display detailed issues
     */
    private function displayDetailedIssues(array $issues): void
    {
        if (!empty($issues['role_without_context'])) {
            $this->warn('=== Roles Without Contexts ===');
            $this->table(
                ['Email', 'Role', 'Expected Context'],
                array_map(fn($i) => [$i['email'], $i['role'], $i['expected_context']], $issues['role_without_context'])
            );
            $this->newLine();
        }

        if (!empty($issues['context_without_role'])) {
            $this->warn('=== Contexts Without Roles ===');
            $this->table(
                ['Email', 'Context', 'Expected Role'],
                array_map(fn($i) => [$i['email'], $i['context'], $i['expected_role']], $issues['context_without_role'])
            );
            $this->newLine();
        }

        if (!empty($issues['inactive_context_with_role'])) {
            $this->warn('=== Inactive Contexts With Active Roles ===');
            $this->table(
                ['Email', 'Context', 'Role'],
                array_map(fn($i) => [$i['email'], $i['context'], $i['role']], $issues['inactive_context_with_role'])
            );
            $this->newLine();
        }

    }

    /**
     * Check if there are any issues
     */
    private function hasIssues(array $issues): bool
    {
        return count($issues['role_without_context']) > 0
            || count($issues['context_without_role']) > 0
            || count($issues['inactive_context_with_role']) > 0;
    }

    /**
     * Fix role without context
     */
    private function fixRoleWithoutContext(User $user, string $roleName, string $expectedContext): void
    {
        // Note: We can't create the context without the actual profile record
        // This would need to be handled manually or by creating the profile
        $this->warn("Cannot auto-fix: User {$user->email} has role '{$roleName}' but no {$expectedContext} profile exists.");
    }

    /**
     * Fix context without role
     */
    private function fixContextWithoutRole(User $user, string $contextType, string $expectedRole): void
    {
        $role = Role::query()->where('name', $expectedRole)->where('guard_name', 'api')->first();
        $contexts = $user->contexts()->where('context_type', $contextType)->where('is_active', true)->get();
        if (!$role || $contexts->isEmpty()) {
            $this->warn("Cannot repair '{$expectedRole}' for {$user->email}: role or active {$contextType} context is missing.");
            return;
        }

        foreach ($contexts as $context) {
            app(\App\Services\UserContextService::class)->assignRolesToContext($user, $context, [$role->id]);
        }
        $this->info("Assigned '{$expectedRole}' through the {$contextType} context for {$user->email}");
    }
    /**
     * Fix inactive context with role
     */
    private function fixInactiveContextWithRole(User $user, string $roleName): void
    {
        $role = Role::query()->where('name', $roleName)->where('guard_name', 'api')->first();
        if (!$role) {
            return;
        }

        $contexts = $user->contexts()->where('is_active', false)->get();
        foreach ($contexts as $context) {
            if ($this->getExpectedRole($context->context_type) !== $roleName
                || !DB::table('user_context_roles')->where('user_context_id', $context->id)->where('role_id', $role->id)->exists()) {
                continue;
            }

            $direct = DB::table('user_direct_role_grants')->where('user_id', $user->id)->where('role_id', $role->id)->exists();
            $unverified = app(\App\Services\UserContextService::class)
                ->hasUnverifiedContextRoleOrigin($user, (int) $role->id);
            if (!$direct && $unverified) {
                $this->warn("Preserved '{$roleName}' for {$user->email}; its historical direct origin is unverified.");
                continue;
            }

            app(\App\Services\UserContextService::class)->revokeRoleFromContext($user, $context, (int) $role->id);
        }
    }
    /**
     * Get expected role for a context type
     */
    private function getExpectedRole(string $contextType): ?string
    {
        $contextRoleMap = [
            'driver' => 'driver',
            'customer' => 'customer',
            'agent' => 'agent',
            'staff' => 'staff',
            'vehicle_owner' => 'vehicle-owner',  // Note: role uses dash
        ];

        return $contextRoleMap[$contextType] ?? null;
    }
}
