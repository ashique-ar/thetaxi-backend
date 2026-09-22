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
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'template_config' => $this->template_config,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
            'url_prefix' => $this->url_prefix,
            'contents_count' => $this->contents_count,
            'children_count' => $this->children_count,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'title' => $this->parent->title,
                'slug' => $this->parent->slug,
            ] : null),
            'children' => CmsContentTypeResource::collection($this->whenLoaded('children')),
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
