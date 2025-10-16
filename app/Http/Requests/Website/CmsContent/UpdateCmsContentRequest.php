<?php
// app/Http/Requests/Website/CmsContent/UpdateCmsContentRequest.php
namespace App\Http\Requests\Website\CmsContent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCmsContentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->route('cms_content')->id;

        return [
            'cms_content_type_id' => ['sometimes', 'required', 'exists:cms_content_types,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', "unique:cms_contents,slug,{$id}"],
            'author' => ['sometimes', 'required', 'string', 'max:255'],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string'],
            'meta_tags' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'nullable', 'integer'],
            'url' => ['sometimes', 'nullable', 'url'],
        ];
    }
}
