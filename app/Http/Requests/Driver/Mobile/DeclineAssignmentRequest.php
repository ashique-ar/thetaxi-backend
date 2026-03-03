<?php

namespace App\Http\Requests\Driver\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decline Assignment Request
 *
 * Validates requests for declining a driver assignment.
 * Requires a decline_reason in the request body.
 *
 * @see Requirement 2.2 - Driver declines assignment with reason
 */
class DeclineAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decline_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decline_reason.required' => 'A reason for declining is required',
            'decline_reason.max' => 'Decline reason must not exceed 1000 characters',
        ];
    }
}
