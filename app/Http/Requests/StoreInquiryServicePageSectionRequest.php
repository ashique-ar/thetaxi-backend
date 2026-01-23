<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryServicePageSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: add permission check if needed
        return $this->user()?->can('website.manage') ?? true;
    }

    public function rules(): array
    {
        $base = [
            'type' => ['required', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];

        // Additional per-type validation rules
        $type = $this->input('type');
        switch ($type) {
            case 'hero':
                $base['data.heading'] = ['required', 'string', 'max:255'];
                $base['data.subheading'] = ['nullable', 'string', 'max:500'];
                $base['data.banner_image'] = ['nullable', 'string', 'max:255'];
                $base['data.form_id'] = ['nullable', 'uuid', 'exists:inquiry_forms,id'];
                $base['data.show_form'] = ['nullable', 'boolean'];
                break;
            case 'features':
                $base['data.heading'] = ['nullable', 'string', 'max:255'];
                $base['data.description'] = ['nullable', 'string', 'max:500'];
                $base['data.vector'] = ['nullable', 'string', 'max:255'];
                $base['data.items'] = ['required', 'array', 'min:1'];
                $base['data.items.*.icon'] = ['nullable', 'string', 'max:255'];
                $base['data.items.*.title'] = ['required', 'string', 'max:255'];
                $base['data.items.*.description'] = ['nullable', 'string', 'max:500'];
                break;
            case 'services':
                $base['data.kicker'] = ['nullable', 'string', 'max:255'];
                $base['data.heading'] = ['nullable', 'string', 'max:255'];
                $base['data.description'] = ['nullable', 'string', 'max:1000'];
                $base['data.image'] = ['nullable', 'string', 'max:255'];
                $base['data.features'] = ['nullable', 'array'];
                $base['data.features.*'] = ['string', 'max:255'];
                break;
            case 'benefits':
                $base['data.kicker'] = ['nullable', 'string', 'max:255'];
                $base['data.heading'] = ['nullable', 'string', 'max:255'];
                $base['data.items'] = ['required', 'array', 'min:1'];
                $base['data.items.*.icon'] = ['nullable', 'string', 'max:255'];
                $base['data.items.*.title'] = ['required', 'string', 'max:255'];
                $base['data.items.*.description'] = ['nullable', 'string', 'max:500'];
                break;
            case 'faq':
                $base['data.kicker'] = ['nullable', 'string', 'max:255'];
                $base['data.heading'] = ['nullable', 'string', 'max:255'];
                $base['data.items'] = ['required', 'array', 'min:1'];
                $base['data.items.*.question'] = ['required', 'string', 'max:255'];
                $base['data.items.*.answer'] = ['required', 'string', 'max:1000'];
                break;
            case 'contact_info':
                $base['data.kicker'] = ['nullable', 'string', 'max:255'];
                $base['data.heading'] = ['nullable', 'string', 'max:255'];
                $base['data.description'] = ['nullable', 'string', 'max:1000'];
                $base['data.contacts'] = ['required', 'array', 'min:1'];
                $base['data.contacts.*.name'] = ['required', 'string', 'max:255'];
                $base['data.contacts.*.title'] = ['nullable', 'string', 'max:255'];
                $base['data.contacts.*.email'] = ['nullable', 'email', 'max:255'];
                $base['data.contacts.*.phone'] = ['nullable', 'string', 'max:50'];
                $base['data.contacts.*.availability'] = ['nullable', 'string', 'max:255'];
                $base['data.office_hours.weekdays'] = ['nullable', 'string', 'max:255'];
                $base['data.office_hours.saturday'] = ['nullable', 'string', 'max:255'];
                $base['data.office_hours.sunday'] = ['nullable', 'string', 'max:255'];
                $base['data.emergency_hotline'] = ['nullable', 'string', 'max:50'];
                $base['data.show_location'] = ['nullable', 'boolean'];
                $base['data.location.address'] = ['nullable', 'string', 'max:255'];
                $base['data.location.map_embed'] = ['nullable', 'string'];
                break;
            case 'services':
            case 'benefits':
                $base['data.heading'] = ['nullable', 'string', 'max:255'];
                $base['data.items'] = ['nullable', 'array'];
                break;
            default:
                // no-op; fallback generic rules apply
                break;
        }

        return $base;
    }
}
