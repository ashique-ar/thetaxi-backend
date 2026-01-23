<?php

namespace App\Http\Resources\Inquiry;

use Illuminate\Http\Resources\Json\JsonResource;

class InquiryServicePageSectionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'inquiry_service_page_id' => $this->inquiry_service_page_id,
            'type' => $this->type,
            'data' => $this->data,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
