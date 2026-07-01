<?php
// app/Http/Resources/ImageGalleryResource.php
namespace App\Http\Resources\ImageGallery;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
        $diskName = config('filesystems.default');
        $disk = Storage::disk($diskName);

        return method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($path, now()->addMinutes(15))
            : $disk->url($path);
    }
}
