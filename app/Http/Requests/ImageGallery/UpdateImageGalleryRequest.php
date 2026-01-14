<?php
// app/Http/Requests/ImageGallery/UpdateImageGalleryRequest.php
namespace App\Http\Requests\ImageGallery;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImageGalleryRequest extends FormRequest
{


    public function rules()
    {
        return [
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'path' => ['sometimes', 'required', 'string'],
            'thumbnail_path' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
