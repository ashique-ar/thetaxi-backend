<?php
// app/Http/Resources/ImageGalleryResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ImageGalleryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'title'          => $this->title,
            'caption'        => $this->caption,
            'path'           => $this->path,
            'thumbnail_path' => $this->thumbnail_path,
            'sort_order'     => $this->sort_order,
            'is_active'      => $this->is_active,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
