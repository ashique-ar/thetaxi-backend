<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept Assignment Request
 *
 * Validates requests for accepting a driver assignment.
 * No body parameters required — the assignment ID comes from the route.
 *
 * @see Requirement 2.1 - Driver accepts assignment
 */
class AcceptAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
