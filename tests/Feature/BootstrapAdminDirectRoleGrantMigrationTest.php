<?php

use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('tracks the known bootstrap admin role idempotently when no context claims it', function () {
    $user = User::factory()->create(['email' => 'admin@casons.lk']);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'api']);
    $user->assignRole($role);
    $migration = require database_path('migrations/2026_10_02_000004_track_bootstrap_admin_direct_role_grant.php');

    $migration->up();
    $migration->up();

    $this->assertDatabaseCount('user_direct_role_grants', 1);
    $this->assertDatabaseHas('user_direct_role_grants', ['user_id' => $user->id, 'role_id' => $role->id]);
});

it('leaves the bootstrap role unclassified when a context already claims it', function () {
    $user = User::factory()->create(['email' => 'admin@casons.lk']);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'api']);
    $user->assignRole($role);
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'corporate',
        'context_id' => (string) Str::uuid(),
        'is_active' => true,
    ]);
    DB::table('user_context_roles')->insert([
        'user_context_id' => $context->id,
        'role_id' => $role->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_10_02_000004_track_bootstrap_admin_direct_role_grant.php');

    $migration->up();

    $this->assertDatabaseMissing('user_direct_role_grants', ['user_id' => $user->id, 'role_id' => $role->id]);
});
