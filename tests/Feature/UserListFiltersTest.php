<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatted_phone_and_verification_and_signup_dates_filter_the_same_list(): void
    {
        $match = User::create([
            'first_name' => 'Match', 'email' => 'match@example.test',
            'phone' => '+94 (77) 123-4567', 'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $match->forceFill(['created_at' => '2026-09-10 12:00:00'])->save();
        User::create([
            'first_name' => 'Other', 'email' => 'other@example.test',
            'phone' => '+94 (76) 123-4567', 'password' => bcrypt('password'),
        ]);

        $users = app(UserService::class)->getAllUsers([
            'search' => '0771234567', 'verified' => 'email',
            'created_from' => '2026-09-10', 'created_to' => '2026-09-10',
        ]);

        $this->assertSame([$match->id], $users->pluck('id')->all());
    }
}
