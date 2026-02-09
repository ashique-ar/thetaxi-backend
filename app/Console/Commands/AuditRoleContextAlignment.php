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
            'legacy_role_names' => [],
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
                ['Legacy role names', count($issues['legacy_role_names'])],
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

        if (!empty($issues['legacy_role_names'])) {
            $this->warn('=== Legacy Role Names ===');
            $this->table(
                ['Email', 'Legacy Role'],
                array_map(fn($i) => [$i['email'], $i['role']], $issues['legacy_role_names'])
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
            || count($issues['inactive_context_with_role']) > 0
            || count($issues['legacy_role_names']) > 0;
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
        if (!$user->hasRole($expectedRole)) {
            $user->assignRole($expectedRole);
            $this->info("✓ Assigned role '{$expectedRole}' to {$user->email}");
        }
    }

    /**
     * Fix inactive context with role
     */
    private function fixInactiveContextWithRole(User $user, string $roleName): void
    {
        // Check if user has other active contexts that justify keeping the role
        $activeContexts = $user->contexts()->where('is_active', true)->get();
        $shouldKeepRole = false;

        foreach ($activeContexts as $context) {
            if ($this->getExpectedRole($context->context_type) === $roleName) {
                $shouldKeepRole = true;
                break;
            }
        }

        if (!$shouldKeepRole) {
            $user->removeRole($roleName);
            $this->info("✓ Removed role '{$roleName}' from {$user->email} (no active contexts)");
        }
    }

    /**
     * Fix legacy role name
     */
    private function fixLegacyRoleName(User $user, string $legacyRole): void
    {
        $newRole = 'vehicle_owner';
        
        if ($user->hasRole($legacyRole)) {
            $user->removeRole($legacyRole);
            $user->assignRole($newRole);
            $this->info("✓ Updated role from '{$legacyRole}' to '{$newRole}' for {$user->email}");
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
