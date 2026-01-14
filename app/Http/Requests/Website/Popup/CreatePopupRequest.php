<?php

namespace App\Http\Requests\Website\Popup;

use App\Models\Website\Popup;
use Illuminate\Foundation\Http\FormRequest;

class CreatePopupRequest extends FormRequest
{


    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Title and content are optional to allow media-only popups
            'title' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:500'],
            'cta_text' => ['nullable', 'string', 'max:100'],
            'cta_link' => ['nullable', 'string', 'max:500'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'display_frequency' => [
                'nullable',
                'string',
                'in:' . implode(',', [
                    Popup::FREQUENCY_ALWAYS,
                    Popup::FREQUENCY_ONCE_PER_SESSION,
                    Popup::FREQUENCY_ONCE_PER_DAY,
                ])
            ],
            'target_pages' => ['nullable', 'array', 'min:1'],
            'target_pages.*' => ['string'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
            'display_frequency.in' => 'Invalid display frequency value.',
            'target_pages.min' => 'At least one target page must be selected.',
        ];
    }
}
