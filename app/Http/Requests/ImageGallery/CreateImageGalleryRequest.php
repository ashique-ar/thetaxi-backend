<?php
// app/Http/Requests/ImageGallery/CreateImageGalleryRequest.php
namespace App\Http\Requests\ImageGallery;

use Illuminate\Foundation\Http\FormRequest;

class CreateImageGalleryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'path' => ['required', 'string'],
            'thumbnail_path' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
