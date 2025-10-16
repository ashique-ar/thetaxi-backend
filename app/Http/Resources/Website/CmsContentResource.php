<?php
// app/Http/Resources/Website/CmsContentResource.php
namespace App\Http\Resources\Website;

use Illuminate\Http\Resources\Json\JsonResource;

class CmsContentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'cms_content_type_id' => $this->cms_content_type_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'author' => $this->author,
            'thumbnail' => $this->thumbnail,
            'body' => $this->body,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_tags' => $this->meta_tags,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
            'url' => $this->url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
