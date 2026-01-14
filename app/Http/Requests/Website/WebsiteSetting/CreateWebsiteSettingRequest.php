<?php
// app/Http/Requests/Website/WebsiteSetting/CreateWebsiteSettingRequest.php
namespace App\Http\Requests\Website\WebsiteSetting;

use Illuminate\Foundation\Http\FormRequest;

class CreateWebsiteSettingRequest extends FormRequest
{


    public function rules()
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'value' => ['nullable'],
        ];
    }
}
