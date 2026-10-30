<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(AllPermissionsSeeder::class);

        // Portal authentication and permissions use the API guard.
        $apiAdminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);

        // Create admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@casons.lk'],
            [
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@casons.lk',
                'password' => Hash::make('casons123'),
                'email_verified_at' => now(),
                'is_active' => true,
                'password_changed_at' => now(),
            ]
        );

        if ($apiAdminRole) {
            $admin->assignRole($apiAdminRole);
        }
        app(\App\Services\UserService::class)->grantDirectRoleGrants(
            $admin,
            collect([$apiAdminRole])->filter()
        );
        $this->command->info('Admin user created successfully!');
        $this->command->info('Email: admin@casons.lk');
        $this->command->info('Password: casons123');
    }
}
