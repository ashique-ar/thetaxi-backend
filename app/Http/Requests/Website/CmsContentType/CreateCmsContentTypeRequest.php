<?php
// app/Http/Requests/Website/CmsContentType/CreateCmsContentTypeRequest.php
namespace App\Http\Requests\Website\CmsContentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCmsContentTypeRequest extends FormRequest
{

    public function rules()
    {
        return [
            'parent_id' => ['nullable', 'uuid', Rule::exists('cms_content_types', 'id')->whereNull('parent_id')],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:cms_content_types,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:255'],
            'template_config' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'display_order' => ['nullable', 'integer'],
            'url_prefix' => ['nullable', 'string', 'max:255'],
        ];
    }
}
