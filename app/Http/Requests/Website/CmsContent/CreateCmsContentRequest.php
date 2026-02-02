<?php
// app/Http/Requests/Website/CmsContent/CreateCmsContentRequest.php
namespace App\Http\Requests\Website\CmsContent;

use Illuminate\Foundation\Http\FormRequest;

class CreateCmsContentRequest extends FormRequest
{


    public function rules()
    {
        return [
            'cms_content_type_id' => ['required', 'exists:cms_content_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:cms_contents,slug'],
            'author' => ['nullable', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'published_at' => ['nullable', 'date'],
            'status' => ['in:draft,published,archived'],
            'excerpt' => ['nullable', 'string'],
            'custom_fields' => ['nullable', 'array'],
            'featured_image' => ['nullable', 'string', 'max:255'],
            'gallery_images' => ['nullable', 'array'],
            'gallery_images.*' => ['string', 'max:255'],
            'views_count' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['boolean'],
            'allow_comments' => ['boolean'],
            'is_ai_generated' => ['boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'meta_tags' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'url' => ['nullable', 'url'],
            'availability_status' => ['nullable', 'in:available,scheduled,unavailable'],
            'read_time' => ['nullable', 'string', 'max:50'],
            'pickup_location' => ['nullable', 'string', 'max:255'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'service_type' => ['nullable', 'string', 'exists:service_types,id'],
            'min_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
