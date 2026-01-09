<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CustomerService - Manages customer creation and profile management
 * 
 * Handles:
 * - Creating customer for guest checkout
 * - Creating customer for new user registration
 * - Updating customer profile after checkout
 * - Linking existing customer to user
 */
class CustomerService
{
    /**
     * Create a customer for guest checkout
     * 
     * Creates both User and Customer records atomically
     * If user with email exists, reuses that user
     * 
     * @param array $data Customer data
     * @return Customer The created customer with user relationship
     */
    public function createGuestCustomer(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            // Check if customer with this NIC already exists (to avoid duplicate NIC constraint)
            $existingNic = $data['customer_identification'] ?? null;
            if ($existingNic) {
                $existingCustomer = Customer::where('nic', $existingNic)->first();
                if ($existingCustomer) {
                    // Customer with this NIC already exists, update their info and return
                    $user = $existingCustomer->user;
                    if ($user) {
                        $user->update([
                            'first_name' => $data['first_name'] ?? explode(' ', $data['customer_name'])[0],
                            'last_name' => $data['last_name'] ?? (explode(' ', $data['customer_name'])[1] ?? ''),
                            'email' => $data['customer_email'] ?? $user->email,
                            'phone' => $data['customer_phone'] ?? $user->phone,
                        ]);
                    }
                    $existingCustomer->update([
                        'address' => $data['customer_address'] ?? $existingCustomer->address,
                        'city' => $data['customer_city'] ?? $existingCustomer->city,
                        'country' => $data['customer_country'] ?? $existingCustomer->country,
                        'country_id' => $data['country_id'] ?? $existingCustomer->country_id,
                    ]);
                    return $existingCustomer->load('user');
                }
            }

            // Check if user with this email already exists
            $user = User::where('email', $data['customer_email'])->first();

            if (!$user) {
                // Create new user account for guest
                $user = User::create([
                    'first_name' => $data['first_name'] ?? explode(' ', $data['customer_name'])[0],
                    'last_name' => $data['last_name'] ?? (explode(' ', $data['customer_name'])[1] ?? ''),
                    'email' => $data['customer_email'],
                    'phone' => $data['customer_phone'] ?? null,
                    'password' => bcrypt(Str::random(16)), // Random password, guest won't login
                    'is_guest' => true,
                    'email_verified_at' => now(),
                ]);
            } else {
                // Update existing user info if needed
                $user->update([
                    'first_name' => $data['first_name'] ?? explode(' ', $data['customer_name'])[0],
                    'last_name' => $data['last_name'] ?? (explode(' ', $data['customer_name'])[1] ?? ''),
                    'phone' => $data['customer_phone'] ?? $user->phone,
                ]);
            }

            // Check if customer record exists for this user
            $customer = $user->customer;

            if (!$customer) {
                // Create new customer linked to user
                $customer = Customer::create([
                    'user_id' => $user->id,
                    'address' => $data['customer_address'] ?? null,
                    'city' => $data['customer_city'] ?? null,
                    'country' => $data['customer_country'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'nic' => $data['customer_identification'] ?? null,
                    'created_user_id' => Auth::id() ?? $user->id,
                ]);
            } else {
                // Update existing customer info
                $customer->update([
                    'address' => $data['customer_address'] ?? $customer->address,
                    'city' => $data['customer_city'] ?? $customer->city,
                    'country' => $data['customer_country'] ?? $customer->country,
                    'country_id' => $data['country_id'] ?? $customer->country_id,
                    'nic' => $data['customer_identification'] ?? $customer->nic,
                    'updated_user_id' => Auth::id() ?? $user->id,
                ]);
            }

            return $customer->load('user');
        });
    }

    /**
     * Create customer for registered user
     * 
     * @param User $user The authenticated user
     * @param array $data Customer profile data
     * @return Customer
     */
    public function createCustomerForUser(User $user, array $data): Customer
    {
        return DB::transaction(function () use ($user, $data) {
            // Check if customer already exists
            if ($user->customer) {
                return $user->customer;
            }

            $customer = Customer::create([
                'user_id' => $user->id,
                'address' => $data['customer_address'] ?? null,
                'city' => $data['customer_city'] ?? null,
                'country_id' => $data['country_id'] ?? null,
                'nic' => $data['customer_identification'] ?? null,
                'dob' => $data['dob'] ?? null,
                'gender' => $data['gender'] ?? null,
                'created_user_id' => $user->id,
            ]);

            return $customer->load('user');
        });
    }

    /**
     * Update customer profile after checkout
     * 
     * Updates existing customer with new information
     * 
     * @param Customer $customer The customer to update
     * @param array $data New customer data
     * @return Customer
     */
    public function updateCustomerProfile(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customer->update([
                'address' => $data['customer_address'] ?? $customer->address,
                'city' => $data['customer_city'] ?? $customer->city,
                'country_id' => $data['country_id'] ?? $customer->country_id,
                'nic' => $data['customer_identification'] ?? $customer->nic,
                'dob' => $data['dob'] ?? $customer->dob,
                'gender' => $data['gender'] ?? $customer->gender,
                'updated_user_id' => Auth::id(),
            ]);

            return $customer->fresh();
        });
    }

    /**
     * Get or create customer from checkout data
     * 
     * If user is authenticated, uses their customer (or creates one)
     * If guest, creates new user + customer
     * 
     * @param array $data Checkout form data
     * @return Customer
     */
    public function getOrCreateCustomer(array $data): Customer
    {
        if (Auth::check()) {
            // Authenticated user - get existing customer or create new
            $user = Auth::user();
            if ($user->customer) {
                // Update existing customer with new checkout data
                return $this->updateCustomerProfile($user->customer, $data);
            } else {
                // Create new customer for existing user
                return $this->createCustomerForUser($user, $data);
            }
        } else {
            // Guest checkout - create new user and customer
            return $this->createGuestCustomer($data);
        }
    }

    /**
     * Get customer by email
     * 
     * Useful for finding existing customer for new bookings
     * 
     * @param string $email Customer email
     * @return Customer|null
     */
    public function getCustomerByEmail(string $email): ?Customer
    {
        return Customer::whereHas('user', function ($query) use ($email) {
            $query->where('email', $email);
        })->first();
    }

    /**
     * Link existing user to customer
     * 
     * @param User $user
     * @param Customer $customer
     * @return Customer
     */
    public function linkUserToCustomer(User $user, Customer $customer): Customer
    {
        $customer->update(['user_id' => $user->id]);
        return $customer->fresh();
    }

    /**
     * Get customer's booking count
     * 
     * @param Customer $customer
     * @return int
     */
    public function getBookingCount(Customer $customer): int
    {
        return $customer->bookings()->count();
    }

    /**
     * Get customer's total spent
     * 
     * @param Customer $customer
     * @return float
     */
    public function getTotalSpent(Customer $customer): float
    {
        return $customer->bookings()
            ->where('status', 'completed')
            ->sum('total_amount') ?? 0;
    }

    /**
     * Get customer with all relationships
     * 
     * @param string $customerId Customer UUID
     * @return Customer|null
     */
    public function getCustomerWithRelations(string $customerId): ?Customer
    {
        return Customer::with([
            'user',
            'country',
            'bookings' => function ($query) {
                $query->latest('created_at')->limit(10);
            }
        ])->find($customerId);
    }

    /**
     * Deactivate customer
     * 
     * @param Customer $customer
     * @return void
     */
    public function deactivateCustomer(Customer $customer): void
    {
        $customer->is_active = false;
        $customer->save();

        // Also deactivate user account
        if ($customer->user) {
            $customer->user->is_active = false;
            $customer->user->save();
        }
    }

    /**
     * Reactivate customer
     * 
     * @param Customer $customer
     * @return void
     */
    public function reactivateCustomer(Customer $customer): void
    {
        $customer->is_active = true;
        $customer->save();

        // Also reactivate user account
        if ($customer->user) {
            $customer->user->is_active = true;
            $customer->user->save();
        }
    }
}
