<?php
// app/Http/Requests/Website/WebsiteSetting/UpdateWebsiteSettingRequest.php
namespace App\Http\Requests\Website\WebsiteSetting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteSettingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'value' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
