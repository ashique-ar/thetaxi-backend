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
            'published_at' => $this->published_at,
            'status' => $this->status,
            'excerpt' => $this->excerpt,
            'custom_fields' => $this->custom_fields,
            'featured_image' => $this->featured_image,
            'gallery_images' => $this->gallery_images,
            'views_count' => $this->views_count,
            'is_featured' => $this->is_featured,
            'allow_comments' => $this->allow_comments,
            'is_ai_generated' => $this->is_ai_generated,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'meta_tags' => $this->meta_tags,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
            'url' => $this->url,
            'availability_status' => $this->availability_status,
            'read_time' => $this->custom_fields['read_time'] ?? null,
            'pickup_location' => $this->pickup_location,
            'pickup_lat' => $this->pickup_lat,
            'pickup_lng' => $this->pickup_lng,
            'dropoff_location' => $this->dropoff_location,
            'dropoff_lat' => $this->dropoff_lat,
            'dropoff_lng' => $this->dropoff_lng,
            'service_type' => $this->service_type,
            'min_days' => $this->min_days ?? 1,
            'full_url' => $this->full_url,
            'content_type' => $this->whenLoaded('contentType', function () {
                return new CmsContentTypeResource($this->contentType);
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->first_name . ' ' . $this->createdBy->last_name,
                ];
            }),
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->first_name . ' ' . $this->updatedBy->last_name,
                ];
            }),
        ];
    }
}
