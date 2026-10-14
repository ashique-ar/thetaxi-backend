<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Laravel\Facades\Image;

class MediaImageProcessor
{
    public function process(UploadedFile $file, ?int $width = null, ?int $height = null, ?int $quality = null): ?array
    {
        if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $dimensions = @getimagesize($file->getRealPath());
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 40000000) {
            throw ValidationException::withMessages(['file' => 'Image must be valid and contain at most 40 megapixels.']);
        }

        $image = Image::decode($file);
        $width ??= config('fileupload.image_processing.max_dimensions.width', 2560);
        $height ??= config('fileupload.image_processing.max_dimensions.height', 2560);
        $quality ??= config('fileupload.image_processing.default_quality', 80);
        $image->scaleDown(width: $width, height: $height);

        $extension = config('fileupload.image_processing.convert_to_webp', true)
            && extension_loaded('gd') && function_exists('imagewebp')
            ? 'webp'
            : $file->getClientOriginalExtension();
        $encoded = $image->encodeUsingFileExtension($extension, quality: $quality);

        return [
            'bytes' => (string) $encoded,
            'extension' => $extension,
            'mime_type' => $encoded->mediaType(),
            'width' => $image->width(),
            'height' => $image->height(),
        ];
    }
}
