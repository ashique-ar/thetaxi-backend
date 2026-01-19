<?php

namespace App\Http\Resources\Inquiry;

use Illuminate\Http\Resources\Json\JsonResource;

class InquiryFormResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'submit_label' => $this->submit_label,
            'success_message' => $this->success_message,
            'settings' => $this->settings,
            'is_active' => $this->is_active,
            'fields' => InquiryFormFieldResource::collection($this->whenLoaded('fields')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
