<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

class PermissionRegistry
{
    public function canonicalGuard(): string
    {
        return config('permissions.canonical_guard', 'api');
    }

    public function legacyGuards(): array
    {
        return config('permissions.legacy_guards', ['web']);
    }

    public function resolveKey(string $key): string
    {
        $key = trim($key);
        return config("permissions.aliases.{$key}", $key);
    }

    public function permissions(?string $guard = null): Collection
    {
        return Permission::query()
            ->when($guard, fn ($query) => $query->where('guard_name', $guard))
            ->orderByRaw("case when guard_name = ? then 0 else 1 end", [$this->canonicalGuard()])
            ->orderBy('name')
            ->get()
            ->unique('name')
            ->map(fn (Permission $permission) => $this->formatPermission($permission))
            ->unique('name')
            ->values();
    }

    public function grouped(?string $guard = null): array
    {
        $moduleConfig = config('permissions.modules', []);
        $permissions = $this->permissions($guard);

        $modules = $permissions
            ->groupBy('module')
            ->map(function (Collection $items, string $module) use ($moduleConfig) {
                $actions = $items->groupBy('action')->map->values();

                return [
                    'key' => $module,
                    'label' => $moduleConfig[$module]['label'] ?? Str::headline(str_replace(['-', '_'], ' ', $module)),
                    'description' => $moduleConfig[$module]['description'] ?? null,
                    'permissions_count' => $items->count(),
                    'actions' => $actions,
                    'permissions' => $items->values(),
                ];
            })
            ->sortKeys()
            ->values();

        return [
            'canonical_guard' => $this->canonicalGuard(),
            'selected_guard' => $guard ?? $this->canonicalGuard(),
            'modules' => $modules,
            'templates' => $this->templates($permissions),
        ];
    }

    public function templates(?Collection $permissions = null): array
    {
        $permissions ??= $this->permissions();

        return collect(config('permissions.templates', []))
            ->map(function (array $template, string $key) use ($permissions) {
                $permissionNames = $this->matchTemplatePermissions(
                    $permissions->pluck('name')->all(),
                    $template['patterns'] ?? ['*'],
                    $template['exclude'] ?? []
                );

                return [
                    'key' => $key,
                    'label' => $template['label'] ?? Str::headline($key),
                    'description' => $template['description'] ?? null,
                    'permissions' => $permissionNames,
                    'permissions_count' => count($permissionNames),
                ];
            })
            ->values()
            ->all();
    }

    public function ensureCanonicalPermissions(array $permissionNames): Collection
    {
        $guard = $this->canonicalGuard();

        return collect($permissionNames)
            ->map(fn ($name) => $this->resolveKey((string) $name))
            ->filter()
            ->unique()
            ->map(fn ($name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]))
            ->values();
    }

    private function formatPermission(Permission $permission): array
    {
        $name = $this->resolveKey($permission->name);
        $parts = explode('.', $name);
        $module = $this->moduleFor($name, $parts[0] ?? 'other');
        $action = $this->actionFor($name);

        return [
            'id' => $permission->id,
            'name' => $name,
            'guard_name' => $permission->guard_name,
            'module' => $module,
            'action' => $action,
            'display_name' => $permission->display_name ?? Str::headline(str_replace(['.', '-', '_'], ' ', $name)),
            'description' => $permission->description,
        ];
    }

    private function moduleFor(string $name, string $fallback): string
    {
        if (Str::startsWith($name, ['user', 'role', 'permission'])) {
            return 'admin';
        }

        if (Str::startsWith($name, ['vehicle', 'driver', 'assignment'])) {
            return 'vehicles';
        }

        if (Str::startsWith($name, ['sms', 'notification'])) {
            return 'communication';
        }

        return $fallback ?: 'other';
    }

    private function actionFor(string $name): string
    {
        $last = Str::of($name)->afterLast('.')->toString();
        $known = ['view', 'create', 'edit', 'update', 'delete', 'manage', 'approve', 'dispatch', 'cancel'];

        return in_array($last, $known, true) ? $last : 'other';
    }

    private function matchTemplatePermissions(array $permissionNames, array $patterns, array $exclude): array
    {
        return collect($permissionNames)
            ->filter(fn ($permission) => $this->matchesAny($permission, $patterns))
            ->reject(fn ($permission) => $this->matchesAny($permission, $exclude))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function matchesAny(string $permission, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || Str::is($pattern, $permission)) {
                return true;
            }
        }

        return false;
    }
}
