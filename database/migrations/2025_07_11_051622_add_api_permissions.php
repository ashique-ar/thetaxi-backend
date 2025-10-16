<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all existing permissions for web guard
        $webPermissions = Permission::where('guard_name', 'web')->get();
        
        // Create the same permissions for api guard
        foreach ($webPermissions as $webPermission) {
            Permission::firstOrCreate([
                'name' => $webPermission->name,
                'guard_name' => 'api'
            ]);
        }
        
        // Get all roles and assign the api permissions
        $webRoles = Role::where('guard_name', 'web')->with('permissions')->get();
        
        foreach ($webRoles as $webRole) {
            // Create or get the same role for api guard
            $apiRole = Role::firstOrCreate([
                'name' => $webRole->name,
                'guard_name' => 'api'
            ]);
            
            // Get the permission names and assign them for api guard
            $permissionNames = $webRole->permissions->pluck('name')->toArray();
            $apiPermissions = Permission::where('guard_name', 'api')
                ->whereIn('name', $permissionNames)
                ->get();
                
            $apiRole->syncPermissions($apiPermissions);
        }
        
        // Assign admin role to admin user for api guard
        $adminUser = \App\Models\User::where('email', 'admin@casons.lk')->first();
        if ($adminUser) {
            $adminApiRole = Role::where('name', 'admin')->where('guard_name', 'api')->first();
            if ($adminApiRole) {
                $adminUser->assignRole($adminApiRole);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove all api guard permissions and roles
        Permission::where('guard_name', 'api')->delete();
        Role::where('guard_name', 'api')->delete();
    }
};
