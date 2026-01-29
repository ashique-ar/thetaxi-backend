<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInquiryServicePageSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO: add permission check if needed
        return $this->user()?->can('website.manage') ?? true;
    }

    public function rules(): array
    {
        $base = [
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $type = $this->input('type') ?? $this->route('section')?->type;

        switch ($type) {
            case 'hero':
                $base['data.heading'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.subheading'] = ['sometimes', 'nullable', 'string', 'max:500'];
                $base['data.banner_image'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.form_id'] = ['sometimes', 'nullable', 'uuid', 'exists:inquiry_forms,id'];
                $base['data.show_form'] = ['sometimes', 'nullable', 'boolean'];
                break;
            case 'features':
                $base['data.heading'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.description'] = ['sometimes', 'nullable', 'string', 'max:500'];
                $base['data.vector'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.items'] = ['sometimes', 'required', 'array', 'min:1'];
                $base['data.items.*.icon'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.items.*.title'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.items.*.description'] = ['sometimes', 'nullable', 'string', 'max:500'];
                break;
            case 'services':
                $base['data.kicker'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.heading'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.description'] = ['sometimes', 'nullable', 'string', 'max:1000'];
                $base['data.image'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.features'] = ['sometimes', 'nullable', 'array'];
                $base['data.features.*'] = ['string', 'max:255'];
                break;
            case 'benefits':
                $base['data.kicker'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.heading'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.items'] = ['sometimes', 'required', 'array', 'min:1'];
                $base['data.items.*.icon'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.items.*.title'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.items.*.description'] = ['sometimes', 'nullable', 'string', 'max:500'];
                break;
            case 'faq':
                $base['data.kicker'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.heading'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.items'] = ['sometimes', 'required', 'array', 'min:1'];
                $base['data.items.*.question'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.items.*.answer'] = ['sometimes', 'required', 'string', 'max:1000'];
                break;
            case 'contact_info':
                $base['data.kicker'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.heading'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.description'] = ['sometimes', 'nullable', 'string', 'max:1000'];
                $base['data.contacts'] = ['sometimes', 'required', 'array', 'min:1'];
                $base['data.contacts.*.name'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.contacts.*.title'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.contacts.*.email'] = ['sometimes', 'nullable', 'email', 'max:255'];
                $base['data.contacts.*.phone'] = ['sometimes', 'nullable', 'string', 'max:50'];
                $base['data.contacts.*.availability'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.office_hours.weekdays'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.office_hours.saturday'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.office_hours.sunday'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.emergency_hotline'] = ['sometimes', 'nullable', 'string', 'max:50'];
                $base['data.show_location'] = ['sometimes', 'nullable', 'boolean'];
                $base['data.location.address'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.location.map_embed'] = ['sometimes', 'nullable', 'string'];
                break;
            case 'form_block':
                $base['data.kicker'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.heading'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.description'] = ['sometimes', 'nullable', 'string', 'max:1000'];
                $base['data.note_title'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.note_body'] = ['sometimes', 'nullable', 'string', 'max:1000'];
                $base['data.form_id'] = ['sometimes', 'nullable', 'uuid', 'exists:inquiry_forms,id'];
                $base['data.show_form'] = ['sometimes', 'nullable', 'boolean'];
                $base['data.steps'] = ['sometimes', 'nullable', 'array'];
                $base['data.steps.*.title'] = ['sometimes', 'required_with:data.steps', 'string', 'max:255'];
                $base['data.steps.*.description'] = ['sometimes', 'nullable', 'string', 'max:500'];
                break;
            case 'content_block':
                $base['data.kicker'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.heading'] = ['sometimes', 'required', 'string', 'max:255'];
                $base['data.body'] = ['sometimes', 'nullable', 'string'];
                $base['data.image'] = ['sometimes', 'nullable', 'string', 'max:255'];
                $base['data.image_position'] = ['sometimes', 'nullable', 'string', 'in:left,right'];
                break;
            default:
                break;
        }

        return $base;
    }
}
