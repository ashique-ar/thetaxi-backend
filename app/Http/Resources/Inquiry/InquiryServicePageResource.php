<?php

namespace App\Http\Resources\Inquiry;

use Illuminate\Http\Resources\Json\JsonResource;

class InquiryServicePageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->service_type_id,
            'service_type' => $this->whenLoaded('serviceType', function () {
                return [
                    'id' => $this->serviceType?->id,
                    'name' => $this->serviceType?->name,
                    'code' => $this->serviceType?->code,
                ];
            }),
            'inquiry_form_id' => $this->inquiry_form_id,
            'form' => new InquiryFormResource($this->whenLoaded('form')),
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'inquiry_type' => $this->inquiry_type,
            'status' => $this->status,
            'content' => $this->content,
            'settings' => $this->settings,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_og_image' => $this->seo_og_image,
            'seo_keywords' => $this->seo_keywords,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
