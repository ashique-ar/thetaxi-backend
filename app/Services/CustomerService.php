<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Models\UserContext;
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
            $data = $this->normalizeCheckoutData($data);
            $email = $data['customer_email'];
            $this->lockCustomerEmail($email);
            
            // CRITICAL FIX: Check if user with this email already exists FIRST
            // This prevents duplicate customer creation and links checkout bookings to the existing customer.
            $user = $this->findUserByEmail($email, true);

            if ($user) {
                // User exists - update their info
                $user->update([
                    'first_name' => $data['first_name'] ?? $this->splitCustomerName($data['customer_name'])[0],
                    'last_name' => $data['last_name'] ?? $this->splitCustomerName($data['customer_name'])[1],
                    'phone' => $data['customer_phone'] ?? $user->phone,
                ]);
                
                return $this->ensureCustomerForUser($user, $data)->load('user');
            }

            // No existing user or customer - create new ones
            [$firstName, $lastName] = $this->splitCustomerName($data['customer_name']);

            $user = User::create([
                'first_name' => $data['first_name'] ?? $firstName,
                'last_name' => $data['last_name'] ?? $lastName,
                'email' => $email,
                'phone' => $data['customer_phone'] ?? null,
                'password' => bcrypt(Str::random(16)), // Random password, guest won't login
                'is_guest' => true,
                'email_verified_at' => now(),
            ]);

            return $this->ensureCustomerForUser($user, $data)->load('user');
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
            return $this->ensureCustomerForUser($user, $this->normalizeCheckoutData($data))->load('user');
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
        $data = $this->normalizeCheckoutData($data);

        if (!empty($data['customer_email'])) {
            $customer = $this->getCustomerByEmail($data['customer_email']);

            if ($customer) {
                return $this->updateCustomerProfile($customer, $data)->load('user');
            }
        }

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
        $email = strtolower(trim($email));

        return Customer::whereHas('user', function ($query) use ($email) {
            $query->whereRaw('LOWER(email) = ?', [$email]);
        })->with('user')->first();
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
            ->sum('total_actual') ?? 0;
    }

    private function ensureCustomerForUser(User $user, array $data): Customer
    {
        $customer = Customer::withTrashed()->where('user_id', $user->id)->lockForUpdate()->first();

        if (!$customer) {
            $customer = new Customer([
                'user_id' => $user->id,
                'created_user_id' => Auth::id() ?? $user->id,
            ]);
        }

        if ($customer->trashed()) {
            $customer->restore();
        }

        $customer->fill([
            'address' => $data['customer_address'] ?? $customer->address,
            'city' => $data['customer_city'] ?? $customer->city,
            'country' => $data['customer_country'] ?? $customer->country,
            'country_id' => $data['country_id'] ?? $customer->country_id,
            'nic' => $data['customer_identification'] ?? $customer->nic,
            'dob' => $data['dob'] ?? $customer->dob,
            'gender' => $data['gender'] ?? $customer->gender,
            'updated_user_id' => Auth::id() ?? $user->id,
        ]);

        if (isset($data['marketing_consent'])) {
            $customer->marketing_consent = (bool) $data['marketing_consent'];
            $customer->marketing_consent_date = $data['marketing_consent'] ? now() : null;
            $customer->marketing_consent_ip = $data['marketing_consent'] ? request()->ip() : null;
        }

        $customer->save();
        $this->ensureCustomerContext($user, $customer);

        return $customer->fresh('user');
    }

    private function ensureCustomerContext(User $user, Customer $customer): void
    {
        $context = UserContext::withTrashed()->firstOrNew([
            'user_id' => $user->id,
            'context_type' => 'customer',
            'context_id' => $customer->id,
        ]);

        if ($context->trashed()) {
            $context->restore();
        }

        $context->fill([
            'is_active' => true,
            'created_user_id' => $context->created_user_id ?? (Auth::id() ?? $user->id),
            'updated_user_id' => Auth::id() ?? $user->id,
        ]);
        $context->save();
    }

    private function findUserByEmail(string $email, bool $lock = false): ?User
    {
        $email = strtolower(trim($email));

        $query = User::where('email', $email);

        if ($lock) {
            $query->lockForUpdate();
        }

        $user = $query->first();

        if ($user) {
            return $user;
        }

        $query = User::whereRaw('LOWER(email) = ?', [$email]);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockCustomerEmail(string $email): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(?)', [(string) sprintf('%u', crc32(strtolower(trim($email))))]);
    }

    private function normalizeCheckoutData(array $data): array
    {
        $email = $data['customer_email'] ?? $data['email'] ?? null;

        if ($email !== null) {
            $data['customer_email'] = strtolower(trim((string) $email));
            $data['email'] = $data['customer_email'];
        }

        $data['customer_name'] = trim((string) ($data['customer_name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))));

        return $data;
    }

    private function splitCustomerName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            $parts[0] ?? 'Customer',
            $parts[1] ?? '',
        ];
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
