<?php

namespace App\Services;

use App\Models\Corporate\CorporateEmployee;
use Illuminate\Validation\ValidationException;

class CorporateAccessLinkService
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function send(CorporateEmployee $employee): void
    {
        $employee->loadMissing(['corporate', 'user', 'userContext']);
        if (! $employee->is_active || ! $employee->corporate?->is_active
            || ! $employee->user?->is_active || ! $employee->userContext?->is_active) {
            throw ValidationException::withMessages([
                'employee' => 'Activate the company and user portal access before sending a setup link.',
            ]);
        }

        $this->auth->sendPasswordResetEmail($employee->user->email);
    }
}
