<?php

namespace App\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

class DriverSettlementExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expense_type' => ['required', 'string', 'max:100'],
            'claimed_amount' => ['required', 'numeric', 'min:0'],
            'approved_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'receipt_files' => ['nullable', 'array'],
            'receipt_files.*.path' => ['nullable', 'string'],
            'receipt_files.*.url' => ['nullable', 'string'],
            'expense_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:pending,approved,partially_approved,rejected'],
            'review_reason' => ['nullable', 'string'],
        ];
    }
}
