<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin role for both guards if they don't exist
        $webAdminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $apiAdminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        
        // Sync permissions for both guards
        $webAdminRole->syncPermissions(Permission::where('guard_name', 'web')->get());
        $apiAdminRole->syncPermissions(Permission::where('guard_name', 'api')->get());
        
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
        
        // Remove any existing role assignments
        $admin->roles()->detach();
        
        // Assign admin role to user for API guard only (since your system uses api guard for authentication)
        if ($apiAdminRole) {
            $admin->assignRole($apiAdminRole);
        }
        
        $this->command->info('Admin user created successfully!');
        $this->command->info('Email: admin@casons.lk');
        $this->command->info('Password: casons123');
    }
}
