<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CustomerCodeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_codes_continue_from_existing_data_and_honor_settings(): void
    {
        $existingUser = $this->user('existing@example.test');
        Customer::create(['user_id' => $existingUser->id, 'code' => 'CUST005']);
        $this->seed(CustomerCodeSettingsSeeder::class);

        BusinessSetting::setSetting('customer_code_prefix', 'CUS-');
        BusinessSetting::setSetting('customer_code_suffix', '-LK');
        BusinessSetting::setSetting('customer_code_digits', '4');

        $customer = Customer::create(['user_id' => $this->user('new@example.test')->id]);

        $this->assertSame('CUS-0006-LK', $customer->code);
    }

    private function user(string $email): User
    {
        return User::create([
            'first_name' => 'Test', 'last_name' => 'User', 'email' => $email,
            'password' => bcrypt('password'), 'is_active' => true,
        ]);
    }
}
