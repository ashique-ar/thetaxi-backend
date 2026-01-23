<?php
// app/Http/Requests/Website/CmsContent/UpdateCmsContentRequest.php
namespace App\Http\Requests\Website\CmsContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCmsContentRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('cms_content')->id;

        return [
            'cms_content_type_id' => ['sometimes', 'required', 'exists:cms_content_types,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', "unique:cms_contents,slug,{$id}"],
            'author' => ['sometimes', 'nullable', 'string', 'max:255'],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'excerpt' => ['sometimes', 'nullable', 'string'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],
            'featured_image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gallery_images' => ['sometimes', 'nullable', 'array'],
            'gallery_images.*' => ['string', 'max:255'],
            'views_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'allow_comments' => ['sometimes', 'boolean'],
            'is_ai_generated' => ['sometimes', 'boolean'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string'],
            'meta_tags' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'nullable', 'integer'],
            'url' => ['sometimes', 'nullable', 'url'],
            'availability_status' => ['sometimes', 'nullable', 'in:available,scheduled,unavailable'],
            'read_time' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
