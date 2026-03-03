<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;

class ProcessApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'comments' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required_if:action,reject', 'string', 'max:1000'],
        ];
    }
}
