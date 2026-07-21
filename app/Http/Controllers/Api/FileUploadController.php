<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserMedia;
use App\Models\ImageGallery;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class FileUploadController extends Controller
{
    /**
     * Constructor - Apply auth middleware
     */
    public function __construct()
    {
        // $this->middleware('auth:api');
        // Apply permission middleware based on category
        $this->middleware('permission:uploads.manage')->only(['uploadFile']);
        $this->middleware('permission:uploads.delete')->only(['deleteFile']);
        $this->middleware('permission:uploads.view')->only(['getFile']);
    }

    /**
     * Universal file upload endpoint
     * POST /api/files/upload
     * 
     * Handles all file uploads with category-based organization
     * Supports both local and S3 storage based on configuration
     */
    public function uploadFile(Request $request): JsonResponse
    {
        // Validate basic requirements
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:51200', // 50MB max
            'category' => 'string',
            'path' => 'string|max:255',
            'thumbnail' => 'boolean',
            'width' => 'integer|min:1|max:4096',
            'height' => 'integer|min:1|max:4096',
            'quality' => 'integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $category = $request->get('category', 'general');
            $customPath = $request->get('path', '');
            $createThumbnail = $request->get('thumbnail', false);

            // Get file information
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getMimeType();
            $fileSize = $file->getSize();
            $extension = $file->getClientOriginalExtension();
            $isImage = str_starts_with($mimeType, 'image/');
            $shouldProcessImage = $isImage && !in_array(strtolower($extension), ['svg', 'ico', 'gif'], true);

            // Generate unique filename
            $fileName = $this->generateUniqueFilename($originalName, $extension);

            // Build storage path
            $storagePath = $this->buildStoragePath($category, $customPath);
            $fullPath = $storagePath . '/' . $fileName;
            $originalFullPath = $fullPath;

            // Validate file type based on category
            $this->validateFileByCategory($file, $category);

            // Process and store the file
            $uploadResult = $this->processAndStoreFile($file, $fullPath, $shouldProcessImage, $request);
            
            // Update the filename and full path if file was converted (e.g., to WebP)
            if ($uploadResult['converted_path'] !== $originalFullPath) {
                $fullPath = $uploadResult['converted_path'];
                $fileName = basename($fullPath);
            }

            // Get user info
            $user = $request->user();

            // Create media record
            $mediaData = [
                'file_name' => $fileName,
                'original_name' => $originalName,
                'file_path' => $storagePath,
                'full_path' => $fullPath,
                'mime_type' => $mimeType,
                'size' => $fileSize,
                'user_id' => $user?->id,
                'category' => $category,
                'is_image' => $isImage,
                'storage_type' => $this->storageDisk(),
            ];

            // Add image dimensions if applicable
            if ($isImage && $uploadResult['dimensions']) {
                $mediaData['width'] = $uploadResult['dimensions']['width'];
                $mediaData['height'] = $uploadResult['dimensions']['height'];
            }

            // Update MIME type if file was converted to WebP
            if ($uploadResult['converted_path'] !== $originalFullPath) {
                $mediaData['mime_type'] = 'image/webp';
            }

            $userMedia = UserMedia::create($mediaData);

            // Create thumbnail if requested and file is an image
            $thumbnailData = null;
            if ($createThumbnail && $isImage) {
                $thumbnailData = $this->createThumbnail($file, $storagePath, $fileName, $userMedia->id, $user);
            }

            DB::commit();

            // Prepare response
            $response = [
                'status' => 'success',
                'message' => 'File uploaded successfully',
                'data' => [
                    'id' => $userMedia->id,
                    'filename' => $fileName,
                    'original_name' => $originalName,
                    'path' => $fullPath,
                    'url' => $this->getFileUrl($fullPath),
                    'mime_type' => $userMedia->mime_type, // Use actual stored MIME type
                    'size' => $fileSize,
                    'category' => $category,
                    'is_image' => $isImage,
                    'storage_type' => $this->storageDisk(),
                ]
            ];

            // Add dimensions if image
            if ($isImage && $uploadResult['dimensions']) {
                $response['data']['width'] = $uploadResult['dimensions']['width'];
                $response['data']['height'] = $uploadResult['dimensions']['height'];
            }

            // Add thumbnail info if created
            if ($thumbnailData) {
                $response['data']['thumbnail'] = $thumbnailData;
            }

            return response()->json($response, 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('File upload failed', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'category' => $request->get('category', 'general'),
                'path' => $request->get('path', ''),
                'disk' => $this->storageDisk(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'File upload failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get file details
     * GET /api/files/{id}
     */
    public function getFile(string $id): JsonResponse
    {
        try {
            $media = UserMedia::find($id);

            if (!$media) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $media->id,
                    'filename' => $media->file_name,
                    'original_name' => $media->original_name,
                    'path' => $media->full_path,
                    'url' => $this->getFileUrl($media->full_path),
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'category' => $media->category,
                    'is_image' => $media->is_image,
                    'width' => $media->width,
                    'height' => $media->height,
                    'created_at' => $media->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve file details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete file
     * DELETE /api/files/{id}
     */
    public function deleteFile(string $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $media = UserMedia::find($id);

            if (!$media) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File not found'
                ], 404);
            }

            // Check if user has permission to delete this file
            if (!$this->canDeleteFile($media)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to delete this file'
                ], 403);
            }

            // Delete from storage
            $this->deleteFromStorage($media->full_path);

            // Delete thumbnail if exists
            $thumbnail = UserMedia::where('parent_id', $media->id)->where('is_thumbnail', true)->first();
            if ($thumbnail) {
                $this->deleteFromStorage($thumbnail->full_path);
                $thumbnail->delete();
            }

            // Delete media record
            $media->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'File deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete file',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Serve file content (for private files or S3 proxying)
     * GET /api/files/serve/{path}
     */
    public function serveFile(Request $request, string $path): \Illuminate\Http\Response
    {
        try {
            // Decode the path
            $decodedPath = base64_decode($path);

            // Check if user has access to this file
            if (!$this->canAccessFile($decodedPath)) {
                abort(403, 'Access denied');
            }

            $disk = Storage::disk($this->storageDisk());
            if (!$disk->exists($decodedPath)) abort(404, 'File not found');
            $fileContent = $disk->get($decodedPath);
            $mimeType = $disk->mimeType($decodedPath);

            return response($fileContent, 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . basename($decodedPath) . '"',
                'Cache-Control' => 'public, max-age=31536000', // 1 year cache
            ]);

        } catch (\Exception $e) {
            abort(500, 'Error serving file');
        }
    }

    public function assets(Request $request, string $path): \Illuminate\Http\Response
    {
        try {
            $disk = Storage::disk($this->storageDisk());
            if (!$disk->exists($path)) abort(404, 'File not found');
            $fileContent = $disk->get($path);
            $mimeType = $disk->mimeType($path);

            return response($fileContent, 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
                'Cache-Control' => 'public, max-age=31536000', // 1 year cache
            ]);
        } catch (\Exception $e) {
            abort(500, 'Error serving file');
        }
    }

    // ========== PRIVATE HELPER METHODS ==========

    /**
     * Generate unique filename
     */
    private function generateUniqueFilename(string $originalName, string $extension): string
    {
        $timestamp = time();
        $random = Str::random(8);
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));

        return $timestamp . '_' . $random . '_' . $safeName . '.' . $extension;
    }

    /**
     * Build storage path based on category and custom path
     */
    private function buildStoragePath(string $category, string $customPath = ''): string
    {
        $basePath = 'media';

        if (!empty($customPath)) {
            return $basePath . '/' . trim($customPath, '/');
        }

        // Default paths by category
        $categoryPaths = [
            'general' => 'general',
            'vehicles' => 'vehicles',
            'gallery' => 'gallery/images',
            'documents' => 'documents',
            'avatars' => 'avatars',
            'thumbnails' => 'thumbnails',
            'thumbnail' => 'thumbnails',
            'branding' => 'branding',
            'logos' => 'logos',
            'logo' => 'logos',
            'banners' => 'banners',
            'breadcrumbs' => 'breadcrumbs',
            'sliders' => 'sliders',
            'team' => 'team',
            'vectors' => 'vectors',
            'icons' => 'icons',
            'sections' => 'sections',
            'heroes' => 'heroes',
            'maps' => 'maps',
            'popups' => 'popups/media',
            'seo' => 'seo',
            'videos' => 'videos',
            'signatures' => 'general/signatures',
        ];

        $categoryPath = $categoryPaths[$category] ?? 'general';

        return $basePath . '/' . $categoryPath;
    }

    /**
     * Validate file based on category
     */
    private function validateFileByCategory($file, string $category): void
    {
        $sizeLimits = config('fileupload.size_limits', []);

        $rules = [
            'general' => ['mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt', 'max:' . ($sizeLimits['general'] ?? 10240)],
            'vehicles' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['vehicles'] ?? 5120)],
            'gallery' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['gallery'] ?? 10240)],
            'documents' => ['mimes:pdf,doc,docx,xls,xlsx,txt,rtf', 'max:' . ($sizeLimits['documents'] ?? 20480)],
            'avatars' => ['image', 'mimes:jpg,jpeg,png,gif', 'max:' . ($sizeLimits['avatars'] ?? 2048)],
            'thumbnails' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['thumbnails'] ?? 10240)],
            'thumbnail' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['thumbnail'] ?? ($sizeLimits['thumbnails'] ?? 10240))],
            'branding' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg,ico', 'max:10240'],
            'logos' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg,ico', 'max:10240'],
            'logo' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg,ico', 'max:10240'],
            'banners' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'breadcrumbs' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'sliders' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'team' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
            'vectors' => ['mimes:svg,png,jpg,jpeg,webp', 'max:10240'],
            'icons' => ['mimes:svg,png,jpg,jpeg,webp,ico', 'max:5120'],
            'sections' => ['mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,pdf', 'max:51200'],
            'heroes' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'maps' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:10240'],
            'popups' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:10240'],
            'seo' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:10240'],
            'videos' => ['mimes:mp4,webm,ogg,mov,avi', 'max:51200'],
            'signatures' => ['image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'],
        ];

        $categoryRules = $rules[$category] ?? $rules['general'];

        $validator = Validator::make(['file' => $file], [
            'file' => ['required', 'file'] + $categoryRules
        ]);

        if ($validator->fails()) {
            throw new \InvalidArgumentException($validator->errors()->first());
        }
    }

    /**
     * Process and store file with optional image processing
     */
    private function processAndStoreFile($file, string $path, bool $isImage, Request $request): array
    {
        $dimensions = null;
        $convertedPath = $path; // Track if path changes due to conversion

        if ($isImage) {
            // Process image with Intervention Image
            $width = $request->get('width');
            $height = $request->get('height');
            $quality = $request->get('quality', 90);

            $image = Image::decode($file);
            $dimensions = [
                'width' => $image->width(),
                'height' => $image->height()
            ];

            // Resize if dimensions specified
            if ($width || $height) {
                // $image->resize($width, $height, function ($constraint) {
                //     $constraint->aspectRatio();
                //     $constraint->upsize();
                // });
                $image->scaleDown(width: $width, height: $height);
                $dimensions = [
                    'width' => $image->width(),
                    'height' => $image->height()
                ];
            }

            // Encode to WebP for better compression (if supported)
            if (extension_loaded('gd') && function_exists('imagewebp')) {
                $encoded = $image->encodeUsingFileExtension('webp', quality: $quality);
                $convertedPath = preg_replace('/\.[^.]+$/', '.webp', $path);
            } else {
                $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
                $encoded = $image->encodeUsingFileExtension($ext, quality: $quality);
            }

            $imageData = (string) $encoded;

            // store those bytes
            $this->storeFile($convertedPath, $imageData);
        } else {
            // Store file as-is
            $this->storeFile($path, file_get_contents($file));
        }

        return [
            'dimensions' => $dimensions,
            'converted_path' => $convertedPath
        ];
    }

    /**
     * Store file to the configured default filesystem disk.
     */
    private function storeFile(string $path, string $content): void
    {
        if (!Storage::disk($this->storageDisk())->put($path, $content)) {
            throw new \RuntimeException("File upload failed for {$path}");
        }
    }

    /**
     * Create thumbnail for images
     */
    private function createThumbnail($file, string $basePath, string $originalFileName, string $parentId, $user): array
    {
        $thumbnailPath = $basePath . '/thumbnails';
        $thumbnailFileName = 'thumb_' . $originalFileName;
        $fullThumbnailPath = $thumbnailPath . '/' . $thumbnailFileName;

        // Create thumbnail image
        $thumbnail = Image::decode($file)
            ->cover(150, 150)
            ->encodeUsingFileExtension('webp', quality: 85);

        $thumbnailData = (string) $thumbnail;

        // Store thumbnail
        $this->storeFile($fullThumbnailPath, $thumbnailData);

        // Create thumbnail media record
        $thumbnailMedia = UserMedia::create([
            'file_name' => $thumbnailFileName,
            'original_name' => 'thumbnail_' . pathinfo($originalFileName, PATHINFO_FILENAME),
            'file_path' => $thumbnailPath,
            'full_path' => $fullThumbnailPath,
            'mime_type' => 'image/webp',
            'size' => strlen($thumbnailData),
            'width' => 150,
            'height' => 150,
            'user_id' => $user?->id,
            'category' => 'thumbnails',
            'is_image' => true,
            'is_thumbnail' => true,
            'parent_id' => $parentId,
            'storage_type' => $this->storageDisk(),
        ]);

        return [
            'id' => $thumbnailMedia->id,
            'filename' => $thumbnailFileName,
            'path' => $fullThumbnailPath,
            'url' => $this->getFileUrl($fullThumbnailPath),
            'size' => $thumbnailMedia->size,
        ];
    }

    /**
     * Get file URL based on storage type
     */
    private function getFileUrl(string $path): string
    {
        $disk = Storage::disk($this->storageDisk());

        return method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($path, now()->addMinutes(15))
            : $disk->url($path);
    }

    /**
     * Delete file from storage
     */
    private function deleteFromStorage(string $path): void
    {
        Storage::disk($this->storageDisk())->delete($path);
    }

    private function storageDisk(): string
    {
        return config('filesystems.default');
    }

    /**
     * Check if user can delete file
     */
    private function canDeleteFile(UserMedia $media): bool
    {
        $user = auth()->user();

        // Super admin can delete any file
        if ($user->hasRole('super-admin')) {
            return true;
        }

        // Users can delete their own files
        if ($media->user_id === $user->id) {
            return true;
        }

        // Check specific permissions
        return $user->can('uploads.delete-any');
    }

    /**
     * Check if user can access file
     */
    private function canAccessFile(string $path): bool
    {
        // For now, allow all authenticated users to access files
        // You can implement more granular permissions here
        return auth()->check();
    }
}
