<?php

use Illuminate\Support\Facades\Storage;

if (!function_exists('getUserfromReq')) {
    function getUserfromReq(Request $request)
    {
        $primaryUser = auth()->user();
        $user = auth()->user();
        
        return [
            'error' => false,
            'user' => $user,
        ];
    }
}

if (!function_exists('image_upload')) {
    function image_upload($destinationPath, $file, $sizex = null, $sizey = null, $imageName = null, $format = null)
    {
        $format = $format ?? 'webp';
        $imageName = $imageName ?? uniqid(time()) . '.' . $format;
        $fullPath = public_path($destinationPath);

        createFileIfNotExist($fullPath);
        Image::read($file)
            ->encodeByExtension($format, 90)
            ->save($fullPath . '/' . $imageName);

        Storage::disk('s3')->put($destinationPath . '/' . $imageName, file_get_contents($fullPath . '/' . $imageName));

        unlink($fullPath . '/' . $imageName);

        return $imageName;
    }
}

if (!function_exists('file_upload')) {
    function file_upload($destinationPath, $file, $fileName = null)
    {
        $extension = $file->getClientOriginalExtension();
        $fileName = ($fileName ?? uniqid(time())) . '.' . $extension;

        $fullPath = public_path($destinationPath);

        $file->move($fullPath, $fileName);

        Storage::disk('s3')->put($destinationPath . '/' . $fileName, file_get_contents($fullPath . '/' . $fileName));

        unlink($fullPath . '/' . $fileName);

        return $fileName;
    }
}

if (!function_exists('s3_asset')) {
    function s3_asset($path, $secure = null)
    {
        return Storage::disk('s3')->url($path);
    }
}

if (!function_exists('check_s3_asset')) {
    function check_s3_asset($path, $secure = null)
    {
        return Storage::disk('s3')->exists($path);
    }
}

if (!function_exists('static_asset')) {
    function static_asset($path, $secure = null)
    {
        return app('url')->asset('public/' . $path, $secure);
    }
}