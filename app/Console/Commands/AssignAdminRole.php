<?php

namespace App\Console\Commands;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Console\Command;

class AssignAdminRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:assign-role';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign API admin role to admin user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::where('email', env('ADMIN_EMAIL', 'admin@example.com'))->first();

        if (!$user) {
            $this->error('Admin user not found');
            return 1;
        }

        // Remove existing roles
        $user->roles()->detach();
        
        // Get API admin role
        $role = Role::where('name', 'admin')->where('guard_name', 'api')->first();
        
        if (!$role) {
            $this->error('API admin role not found');
            return 1;
        }

        $user->assignRole($role);
        
        $this->info('API admin role assigned successfully!');
        $this->info('User now has roles: ' . implode(', ', $user->getRoleNames()->toArray()));
        
        return 0;
    }
}
