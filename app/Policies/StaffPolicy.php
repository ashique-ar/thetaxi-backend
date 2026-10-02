<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;
use App\Services\StaffAccessService;

class StaffPolicy
{
    public function __construct(private readonly StaffAccessService $access) {}

    public function view(User $user, Staff $staff): bool
    {
        return $this->access->allows($user, $staff, 'view');
    }

    public function update(User $user, Staff $staff): bool
    {
        return $this->access->allows($user, $staff, 'edit');
    }

    public function terminate(User $user, Staff $staff): bool
    {
        return $this->access->allows($user, $staff, 'terminate');
    }
}
