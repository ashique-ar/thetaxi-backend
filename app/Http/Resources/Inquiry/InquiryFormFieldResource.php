<?php

namespace App\Http\Resources\Inquiry;

use Illuminate\Http\Resources\Json\JsonResource;

class InquiryFormFieldResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_form_id' => $this->inquiry_form_id,
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'icon' => $this->icon,
            'placeholder' => $this->placeholder,
            'help_text' => $this->help_text,
            'is_required' => $this->is_required,
            'validation_rules' => $this->validation_rules,
            'options' => $this->options,
            'default_value' => $this->default_value,
            'width' => $this->width,
            'sort_order' => $this->sort_order,
            'conditional_logic' => $this->conditional_logic,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
