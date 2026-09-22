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
            'services_count' => $this->whenCounted('cmsServices'),
            'fields' => InquiryFormFieldResource::collection($this->whenLoaded('fields')),
            'services' => $this->whenLoaded('cmsServices', fn () => $this->cmsServices->map(fn ($service) => [
                'id' => $service->id,
                'title' => $service->title,
                'slug' => $service->slug,
                'status' => $service->status,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
