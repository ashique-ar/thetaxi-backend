<?php
// app/Http/Requests/Website/CmsContent/CreateCmsContentRequest.php
namespace App\Http\Requests\Website\CmsContent;

use Illuminate\Foundation\Http\FormRequest;

class CreateCmsContentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'cms_content_type_id' => ['required', 'exists:cms_content_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:cms_contents,slug'],
            'author' => ['required', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'meta_tags' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'url' => ['nullable', 'url'],
        ];
    }
}
