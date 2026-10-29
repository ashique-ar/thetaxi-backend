<?php

namespace App\Console\Commands;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignAdminRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:assign-role {--role=admin : Role to assign (admin or super-admin)}';

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

        $roleName = (string) $this->option('role');
        if (!in_array($roleName, ['admin', 'super-admin'], true)) {
            $this->error('Choose either admin or super-admin.');
            return 1;
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
        
        if (!$role) {
            $this->error('API admin role not found');
            return 1;
        }

        DB::transaction(function () use ($user, $role): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->assignRole($role);
            app(\App\Services\UserService::class)->grantDirectRoleGrants($user, collect([$role]));
        });
        
        $this->info("API {$roleName} role assigned successfully!");
        $this->info('User now has roles: ' . implode(', ', $user->getRoleNames()->toArray()));
        
        return 0;
    }
}
