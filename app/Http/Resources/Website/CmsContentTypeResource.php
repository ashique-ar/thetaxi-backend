<?php
// app/Http/Resources/Website/CmsContentTypeResource.php
namespace App\Http\Resources\Website;

use Illuminate\Http\Resources\Json\JsonResource;

class CmsContentTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'template_config' => $this->template_config,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
            'url_prefix' => $this->url_prefix,
            'contents_count' => $this->whenLoaded('contents', function () {
                return $this->contents->count();
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
        ];
    }
}
