<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AccountActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleted_customer_activity_keeps_actor_and_can_be_restored(): void
    {
        activity()->enableLogging();
        $admin = User::create([
            'first_name' => 'Audit',
            'last_name' => 'Admin',
            'email' => 'audit-admin@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        Permission::findOrCreate('customers.view', 'api');
        Permission::findOrCreate('customers.delete', 'api');
        $admin->givePermissionTo(['customers.view', 'customers.delete']);
        $customerUser = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $customer = Customer::create(['user_id' => $customerUser->id]);

        $this->actingAs($admin, 'api')->deleteJson("/api/customers/{$customer->id}")->assertOk();
        $this->actingAs($admin, 'api')->getJson('/api/account-activities?event=deleted')
            ->assertOk()->assertJsonPath('data.data.0.actor.id', $admin->id);
        $this->actingAs($admin, 'api')->postJson("/api/account-activities/customers/{$customer->id}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted($customer);
    }
}
