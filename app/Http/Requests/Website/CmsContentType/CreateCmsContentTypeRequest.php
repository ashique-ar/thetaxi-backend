<?php
// app/Http/Requests/Website/CmsContentType/CreateCmsContentTypeRequest.php
namespace App\Http\Requests\Website\CmsContentType;

use Illuminate\Foundation\Http\FormRequest;

class CreateCmsContentTypeRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'title'       => ['nullable','string','max:255'],
            'slug'        => ['required','string','max:255','unique:cms_content_types,slug'],
            'description' => ['nullable','string'],
        ];
    }
}
