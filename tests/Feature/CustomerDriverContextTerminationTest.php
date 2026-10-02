<?php

use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('terminates only the deleted Customer or Driver context and suspends its context role', function () {
    [$admin] = hr_seed_admin_actor();
    $user = User::factory()->create();
    $customer = Customer::create(['user_id' => $user->id]);
    $driver = Driver::create(['user_id' => $user->id]);
    $customerContext = UserContext::create(['user_id' => $user->id, 'context_type' => 'customer', 'context_id' => $customer->id, 'is_active' => true]);
    $driverContext = UserContext::create(['user_id' => $user->id, 'context_type' => 'driver', 'context_id' => $driver->id, 'is_active' => true]);
    $customerRole = Role::query()->where('name', 'customer')->where('guard_name', 'api')->firstOrFail();
    $driverRole = Role::query()->where('name', 'driver')->where('guard_name', 'api')->firstOrFail();
    foreach ([[$customerContext, $customerRole], [$driverContext, $driverRole]] as [$context, $role]) {
        DB::table('user_context_roles')->insert(['user_context_id' => $context->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $user->id]);
    }

    actingAs($admin, 'api')->deleteJson('/api/customers/'.$customer->id)->assertOk();
    $this->assertDatabaseHas('user_contexts', ['id' => $customerContext->id, 'is_active' => false, 'updated_user_id' => $admin->id]);
    $this->assertDatabaseHas('user_contexts', ['id' => $driverContext->id, 'is_active' => true]);
    $this->assertDatabaseMissing('model_has_roles', ['role_id' => $customerRole->id, 'model_type' => User::class, 'model_id' => $user->id]);

    actingAs($admin, 'api')->deleteJson('/api/drivers/'.$driver->id)->assertOk();
    $this->assertDatabaseHas('user_contexts', ['id' => $driverContext->id, 'is_active' => false, 'updated_user_id' => $admin->id]);
    $this->assertDatabaseMissing('model_has_roles', ['role_id' => $driverRole->id, 'model_type' => User::class, 'model_id' => $user->id]);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
});
