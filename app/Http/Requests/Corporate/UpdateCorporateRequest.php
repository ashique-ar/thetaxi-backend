<?php

namespace App\Http\Requests\Corporate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCorporateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $corporateId = $this->route('corporate')?->id ?? $this->route('corporate');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('corporates', 'name')
                    ->ignore($corporateId)
                    ->whereNull('deleted_at'),
            ],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'string'],
            'approval_required' => ['boolean'],
            'exempt_coordinator_from_approval' => ['boolean'],
            'coordinator_can_view_payments' => ['boolean'],
        ];
    }
}
