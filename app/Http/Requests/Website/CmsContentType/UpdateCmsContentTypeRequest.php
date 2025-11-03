<?php
// app/Http/Requests/Website/CmsContentType/UpdateCmsContentTypeRequest.php
namespace App\Http\Requests\Website\CmsContentType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCmsContentTypeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->route('cms_content_type')->id;

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', "unique:cms_content_types,slug,{$id}"],
            'description' => ['sometimes', 'nullable', 'string'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'template_config' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'nullable', 'integer'],
            'url_prefix' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
