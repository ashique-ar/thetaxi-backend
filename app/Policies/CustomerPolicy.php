<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->can('customers.view')) {
            return true;
        }

        // Customer can view their own profile
        $customerContext = $user->customerContext();
        return $customerContext && $customerContext->context_id === $customer->id;
    }

    public function create(User $user): bool
    {
        return $user->can('customers.create');
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->can('customers.edit')) {
            return true;
        }

        // Customer can update their own profile
        $customerContext = $user->customerContext();
        return $customerContext && $customerContext->context_id === $customer->id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete');
    }
}
