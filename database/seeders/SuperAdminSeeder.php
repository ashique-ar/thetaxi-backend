<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (!$email || !$password) {
            throw new \RuntimeException('Set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD before running SuperAdminSeeder.');
        }

        $this->call(AllPermissionsSeeder::class);

        DB::transaction(function () use ($email, $password): void {
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => env('SUPER_ADMIN_FIRST_NAME', 'Super'),
                    'last_name' => env('SUPER_ADMIN_LAST_NAME', 'Admin'),
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'password_changed_at' => now(),
                ]
            );

            $roles = collect([
                Role::where('name', 'super-admin')
                    ->where('guard_name', config('permissions.canonical_guard', 'api'))
                    ->firstOrFail(),
            ]);
            $user->assignRole($roles->first());
            app(\App\Services\UserService::class)->grantDirectRoleGrants($user, $roles);
        });

        $this->command?->info("Super-admin account is ready for {$email}.");
    }
}
