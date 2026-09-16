<?php
// app/Http/Requests/Website/CmsContentType/UpdateCmsContentTypeRequest.php
namespace App\Http\Requests\Website\CmsContentType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCmsContentTypeRequest extends FormRequest
{


    public function rules()
    {
        $id = $this->route('cms_content_type')->id;

        return [
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('cms_content_types', 'id')->whereNull('parent_id'), Rule::notIn([$id])],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
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
