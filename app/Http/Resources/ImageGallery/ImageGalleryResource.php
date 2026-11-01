<?php
// app/Http/Resources/ImageGalleryResource.php
namespace App\Http\Resources\ImageGallery;

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
            'url'            => $this->path ? $this->storageUrl($this->path) : null,
            'thumbnail_url'  => $this->thumbnail_path ? $this->storageUrl($this->thumbnail_path) : null,
            'sort_order'     => $this->sort_order,
            'is_active'      => $this->is_active,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }

    private function storageUrl(string $path): string
    {
        return resource_proxy_url($path, config('filesystems.default', 's3'));
    }
}
