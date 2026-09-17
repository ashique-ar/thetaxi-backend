<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserMobileLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_a_formatted_mobile_and_reports_existing_context(): void
    {
        $operator = User::create([
            'first_name' => 'Portal', 'last_name' => 'Operator',
            'email' => 'operator@example.test', 'password' => bcrypt('password'), 'is_active' => true,
        ]);
        Permission::findOrCreate('customers.create', 'api');
        $operator->givePermissionTo('customers.create');

        $user = User::create([
            'first_name' => 'Existing', 'last_name' => 'User',
            'email' => 'existing@example.test', 'phone' => '+94 (77) 123-4567',
            'password' => bcrypt('password'), 'is_active' => true,
        ]);
        UserContext::create([
            'user_id' => $user->id, 'context_type' => 'customer',
            'context_id' => (string) Str::uuid(), 'is_active' => true,
        ]);

        $this->actingAs($operator, 'api')
            ->getJson('/api/users/lookup-by-mobile?mobile=%2B94771234567&context=customer')
            ->assertOk()
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.has_context', true);
    }
}
