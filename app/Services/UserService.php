<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;

class UserService
{
    /**
     * Get all users with filters and pagination
     *
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getAllUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::with(['role', 'agent', 'permissions', 'roles', 'contexts']);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['role'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        if (!empty($filters['context'])) {
            $query->whereHas('contexts', function ($q) use ($filters) {
                $q->where('context_type', $filters['context'])
                  ->where('is_active', true);
            });
        }

        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (!empty($filters['verified'])) {
            if ($filters['verified'] === 'email') {
                $query->whereNotNull('email_verified_at');
            } elseif ($filters['verified'] === 'phone') {
                $query->whereNotNull('phone_verified_at');
            }
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Paginate results
        $perPage = $filters['per_page'] ?? 15;
        return $query->paginate($perPage);
    }

    /**
     * Create a new user
     *
     * @param array $userData
     * @return User
     * @throws \Exception
     */
    public function createUser(array $userData): User
    {
        DB::beginTransaction();
        try {
            // Hash password if provided
            if (isset($userData['password'])) {
                $userData['password'] = Hash::make($userData['password']);
            }

            // Set default values
            $userData['is_active'] = $userData['is_active'] ?? true;
            $userData['password_changed_at'] = now();

            $user = User::create($userData);

            // Assign default role if not provided
            if (!isset($userData['role_id']) && !isset($userData['roles'])) {
                $defaultRole = \Spatie\Permission\Models\Role::where('name', 'customer')->first();
                if ($defaultRole) {
                    $user->assignRole($defaultRole);
                }
            }

            // Assign roles if provided
            if (isset($userData['roles'])) {
                $user->assignRole($userData['roles']);
            }

            // Assign permissions if provided
            if (isset($userData['permissions'])) {
                $user->givePermissionTo($userData['permissions']);
            }

            DB::commit();
            return $user->load(['role', 'agent', 'permissions', 'roles']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update user
     *
     * @param User $user
     * @param array $userData
     * @return User
     * @throws \Exception
     */
    public function updateUser(User $user, array $userData): User
    {
        DB::beginTransaction();
        try {
            // Hash password if provided
            if (isset($userData['password'])) {
                $userData['password'] = Hash::make($userData['password']);
                $userData['password_changed_at'] = now();
            }

            $user->update($userData);

            // Update roles if provided
            if (isset($userData['roles'])) {
                $user->syncRoles($userData['roles']);
            }

            // Update permissions if provided
            if (isset($userData['permissions'])) {
                $user->syncPermissions($userData['permissions']);
            }

            DB::commit();
            return $user->load(['role', 'agent', 'permissions', 'roles']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete user
     *
     * @param User $user
     * @return bool
     * @throws \Exception
     */
    public function deleteUser(User $user): bool
    {
        DB::beginTransaction();
        try {
            // Revoke all tokens
            $user->revokeAllTokens();

            // Soft delete user
            $user->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Activate user
     *
     * @param User $user
     * @return User
     */
    public function activateUser(User $user): User
    {
        $user->update(['is_active' => true]);
        return $user;
    }

    /**
     * Deactivate user
     *
     * @param User $user
     * @return User
     */
    public function deactivateUser(User $user): User
    {
        // Revoke all tokens when deactivating
        $user->revokeAllTokens();
        $user->update(['is_active' => false]);
        return $user;
    }

    /**
     * Reset user password
     *
     * @param User $user
     * @param string $newPassword
     * @param bool $notifyUser
     * @return User
     */
    public function resetUserPassword(User $user, string $newPassword, bool $notifyUser = false): User
    {
        $user->update([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ]);

        // Revoke all tokens to force re-login
        $user->revokeAllTokens();

        // Notify user if requested
        if ($notifyUser) {
            $user->notify(new PasswordResetNotification($newPassword));
        }

        return $user;
    }

    /**
     * Get user statistics
     *
     * @param User $user
     * @return array
     */
    public function getUserStatistics(User $user): array
    {
        return [
            'total_bookings' => $user->bookings()->count(),
            'active_sessions' => $user->tokens()->where('revoked', false)->count(),
            'last_login' => $user->last_login_at,
            'account_created' => $user->created_at,
            'email_verified' => $user->hasVerifiedEmail(),
            'phone_verified' => $user->hasVerifiedPhone(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }

    /**
     * Bulk activate users
     *
     * @param array $userIds
     * @return int
     */
    public function bulkActivateUsers(array $userIds): int
    {
        return User::whereIn('id', $userIds)->update(['is_active' => true]);
    }

    /**
     * Bulk deactivate users
     *
     * @param array $userIds
     * @return int
     */
    public function bulkDeactivateUsers(array $userIds): int
    {
        // Revoke all tokens for deactivated users
        $users = User::whereIn('id', $userIds)->get();
        foreach ($users as $user) {
            $user->revokeAllTokens();
        }

        return User::whereIn('id', $userIds)->update(['is_active' => false]);
    }

    /**
     * Export users to CSV
     *
     * @param array $filters
     * @return string
     */
    public function exportUsers(array $filters = []): string
    {
        $users = $this->getAllUsers($filters);
        
        $csv = "ID,First Name,Last Name,Email,Phone,Role,Status,Created At\n";
        
        foreach ($users as $user) {
            $csv .= implode(',', [
                $user->id,
                $user->first_name,
                $user->last_name,
                $user->email,
                $user->phone,
                $user->role->name ?? '',
                $user->is_active ? 'Active' : 'Inactive',
                $user->created_at->format('Y-m-d H:i:s')
            ]) . "\n";
        }
        
        return $csv;
    }

    /**
     * Get user activity log
     *
     * @param User $user
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getUserActivityLog(User $user, int $limit = 50): \Illuminate\Support\Collection
    {
        return $user->activities()
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Check if user can be deleted
     *
     * @param User $user
     * @return bool
     */
    public function canDeleteUser(User $user): bool
    {
        // Check if user has any bookings
        return $user->bookings()->count() === 0;
    }

    /**
     * Get user dashboard data
     *
     * @param User $user
     * @return array
     */
    public function getUserDashboardData(User $user): array
    {
        return [
            'profile' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'email' => $user->email,
                'avatar' => $user->avatar_url,
                'role' => $user->role->name ?? null,
            ],
            'statistics' => $this->getUserStatistics($user),
            'recent_activities' => $this->getUserActivityLog($user, 10),
            'permissions' => $user->getPermissionsArray(),
            'roles' => $user->getRolesArray(),
        ];
    }
}
