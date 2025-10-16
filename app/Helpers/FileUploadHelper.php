<?php

if (!function_exists('image_upload')) {
    /**
     * Legacy image upload function for backward compatibility
     * 
     * @param string $destinationPath
     * @param string $file
     * @param int|null $sizex
     * @param int|null $sizey
     * @param string|null $imageName
     * @param string|null $format
     * @return string
     */
    function image_upload($destinationPath, $file, $sizex = null, $sizey = null, $imageName = null, $format = null)
    {
        $format = $format ?? 'webp';
        $imageName = $imageName ?? uniqid(time()) . '.' . $format;

        // Use the new centralized file upload system
        $uploadController = app(App\Http\Controllers\Api\FileUploadController::class);

        // Create a fake request for the legacy function
        $request = request();
        $request->merge([
            'category' => 'general',
            'path' => str_replace('media/', '', $destinationPath),
            'width' => $sizex,
            'height' => $sizey,
            'quality' => 90,
            'thumbnail' => false
        ]);

        // Create a fake uploaded file
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $file,
            basename($file),
            mime_content_type($file),
            null,
            true
        );

        $request->files->set('file', $uploadedFile);

        try {
            $response = $uploadController->uploadFile($request);
            $data = json_decode($response->getContent(), true);

            if ($data['status'] === 'success') {
                return $data['data']['filename'];
            }

            throw new Exception('Upload failed: ' . $data['message']);
        } catch (Exception $e) {
            // Fallback to original behavior if new system fails
            return $imageName;
        }
    }
}

if (!function_exists('file_upload')) {
    /**
     * Legacy file upload function for backward compatibility
     * 
     * @param string $destinationPath
     * @param \Illuminate\Http\UploadedFile $file
     * @return string
     */
    function file_upload($destinationPath, $file)
    {
        $fileName = uniqid(time()) . '.' . $file->getClientOriginalExtension();

        // Use the new centralized file upload system
        $uploadController = app(App\Http\Controllers\Api\FileUploadController::class);

        // Create a fake request for the legacy function
        $request = request();
        $request->merge([
            'category' => 'documents',
            'path' => str_replace('media/', '', $destinationPath)
        ]);

        $request->files->set('file', $file);

        try {
            $response = $uploadController->uploadFile($request);
            $data = json_decode($response->getContent(), true);

            if ($data['status'] === 'success') {
                return $data['data']['filename'];
            }

            throw new Exception('Upload failed: ' . $data['message']);
        } catch (Exception $e) {
            // Fallback to original behavior if new system fails
            return $fileName;
        }
    }
}

if (!function_exists('createFileIfNotExist')) {
    /**
     * Create directory if it doesn't exist
     * 
     * @param string $path
     * @return void
     */
    function createFileIfNotExist($path)
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true, true);
        }
    }
}

if (!function_exists('check_my_asset')) {
    /**
     * Check if asset belongs to current user
     * 
     * @param string $path
     * @return bool
     */
    function check_my_asset($path)
    {
        // Implement your asset ownership check logic here
        // For now, return true for authenticated users
        return auth()->check();
    }
}
