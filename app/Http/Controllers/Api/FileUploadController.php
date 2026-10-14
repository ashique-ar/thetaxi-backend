<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserMedia;
use App\Models\ImageGallery;
use App\Services\MediaImageProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $this->middleware('permission:uploads.manage')->only(['uploadFile', 'updateFile']);
        $this->middleware('permission:uploads.delete')->only(['deleteFile']);
        $this->middleware('permission:uploads.view|uploads.manage')->only(['getFile', 'index', 'serveFile']);
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
            'file' => 'required|file|max:' . config('fileupload.max_video_size', 102400),
            'category' => 'string|max:50|regex:/^[a-z0-9_-]+$/',
            'path' => ['string', 'max:255', 'regex:/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)*$/'],
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

        $this->validateFileByCategory($request->file('file'), $request->get('category', 'general'));

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
            // Preserve animation and vector/icon formats; compress raster photos automatically.
            $shouldProcessImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true);

            // Generate unique filename
            $fileName = $this->generateUniqueFilename($originalName, $extension);

            // Build storage path
            $storagePath = $this->buildStoragePath($category, $customPath);
            $fullPath = $storagePath . '/' . $fileName;
            $originalFullPath = $fullPath;

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
                'size' => $uploadResult['size'],
                'metadata' => ['original_size' => $fileSize],
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
                    'size' => $uploadResult['size'],
                    'original_size' => $fileSize,
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

            DB::commit();
            return response()->json($response, 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            if (isset($fullPath)) {
                Storage::disk($this->storageDisk())->delete($fullPath);
                if (!empty($createThumbnail)) {
                    Storage::disk($this->storageDisk())->delete($storagePath . '/thumbnails/thumb_' . pathinfo($fileName, PATHINFO_FILENAME) . '.webp');
                }
            }

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

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:50',
            'type' => 'nullable|in:image,video,document',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        // Images and videos are shared library assets; private documents remain owner scoped.
        $query = UserMedia::withInactive()->mainFiles()->where(function ($query) use ($request) {
            $query->where('is_image', true)
                ->orWhere('mime_type', 'like', 'video/%')
                ->orWhere('user_id', $request->user()->id);
        });
        if (!empty($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['type'])) {
            match ($filters['type']) {
                'image' => $query->where('is_image', true),
                'video' => $query->where('mime_type', 'like', 'video/%'),
                'document' => $query->where('is_image', false)->where('mime_type', 'not like', 'video/%'),
            };
        }
        $media = $query->latest()->paginate($filters['per_page'] ?? 24);
        return response()->json([
            'status' => 'success',
            'data' => $media->getCollection()->map(fn (UserMedia $file) => [
                'id' => $file->id,
                'filename' => $file->file_name,
                'original_name' => $file->original_name,
                'path' => $file->full_path,
                'url' => $file->url,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'original_size' => $file->metadata['original_size'] ?? $file->size,
                'category' => $file->category,
                'width' => $file->width,
                'height' => $file->height,
                'can_rename' => $file->user_id === $request->user()->id,
                'can_delete' => $file->user_id === $request->user()->id
                    || $request->user()->hasRole('super-admin')
                    || $request->user()->can('uploads.delete-any'),
                'created_at' => $file->created_at,
            ]),
            'meta' => ['total' => $media->total(), 'per_page' => $media->perPage(), 'current_page' => $media->currentPage()],
        ]);
    }

    /**
     * Get file details
     * GET /api/files/{id}
     */
    public function getFile(string $id): JsonResponse
    {
        try {
            $media = UserMedia::withInactive()->find($id);

            if (!$media) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'File not found'
                ], 404);
            }

            if ($media->user_id !== auth()->id()) {
                return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $media->id,
                    'filename' => $media->file_name,
                    'original_name' => $media->original_name,
                    'path' => $media->full_path,
                    'url' => $media->url,
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

    public function updateFile(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['original_name' => 'required|string|max:255']);
        $media = UserMedia::withInactive()->findOrFail($id);
        abort_unless($media->user_id === $request->user()->id, 403);
        $media->update($data);
        return response()->json(['status' => 'success', 'message' => 'Media renamed']);
    }

    /**
     * Delete file
     * DELETE /api/files/{id}
     */
    public function deleteFile(string $id): JsonResponse
    {
        try {
            $media = UserMedia::withInactive()->find($id);

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

            DB::beginTransaction();
            // The model deletes the file and thumbnails using their recorded storage disk.
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
        $decodedPath = base64_decode($path, true);
        abort_unless($decodedPath !== false, 404, 'File not found.');

        $media = UserMedia::withInactive()->where('full_path', $decodedPath)->firstOrFail();
        $user = $request->user();
        abort_unless($user && ($media->user_id === $user->id || $user->hasRole('super-admin') || $user->can('uploads.delete-any')), 403,
            'Access denied.');

        $disk = Storage::disk($media->storage_type ?: config('filesystems.default'));
        abort_unless($disk->exists($decodedPath), 404, 'File not found.');
        $mimeType = $disk->mimeType($decodedPath) ?: 'application/octet-stream';

        return response($disk->get($decodedPath), 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($decodedPath) . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function assets(Request $request, string $path): \Illuminate\Http\Response
    {
        $media = UserMedia::query()->where('full_path', $path)->first();
        $publicCategories = ['general', 'vehicles', 'gallery', 'branding', 'logos', 'banners', 'breadcrumbs', 'sliders', 'team', 'vectors', 'icons', 'sections', 'heroes', 'maps', 'popups', 'seo', 'videos'];
        $isPublicMedia = $media
            && in_array($media->category, $publicCategories, true)
            && ($media->is_image || str_starts_with($media->mime_type, 'video/'));
        abort_unless($isPublicMedia || $request->hasValidSignature(), 404, 'File not found');

        $diskName = $isPublicMedia ? $media->storage_type : $request->query('disk', $this->storageDisk());
        abort_unless(is_string($diskName) && config('filesystems.disks.' . $diskName), 404, 'File not found');
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404, 'File not found');
        $fileContent = $disk->get($path);
        $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

        return response($fileContent, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control' => $isPublicMedia ? 'public, max-age=31536000' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
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
            'general' => ['mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt,mp4,webm,ogg,mov', 'max:' . ($sizeLimits['general'] ?? 10240)],
            'vehicles' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['vehicles'] ?? 5120)],
            'gallery' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['gallery'] ?? 10240)],
            'documents' => ['mimes:pdf,doc,docx,xls,xlsx,txt,rtf,jpg,jpeg,png,webp', 'max:' . ($sizeLimits['documents'] ?? 20480)],
            'avatars' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['avatars'] ?? 2048)],
            'thumbnails' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['thumbnails'] ?? 10240)],
            'thumbnail' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:' . ($sizeLimits['thumbnail'] ?? ($sizeLimits['thumbnails'] ?? 10240))],
            'branding' => ['mimes:jpg,jpeg,png,gif,webp,svg,ico', 'max:10240'],
            'logos' => ['mimes:jpg,jpeg,png,gif,webp,svg,ico', 'max:10240'],
            'logo' => ['mimes:jpg,jpeg,png,gif,webp,svg,ico', 'max:10240'],
            'banners' => ['mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,mov', 'max:20480'],
            'breadcrumbs' => ['mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'sliders' => ['mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'team' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
            'vectors' => ['mimes:svg,png,jpg,jpeg,webp', 'max:10240'],
            'icons' => ['mimes:svg,png,jpg,jpeg,webp,ico', 'max:5120'],
            'sections' => ['mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,pdf', 'max:51200'],
            'heroes' => ['mimes:jpg,jpeg,png,gif,webp,svg', 'max:20480'],
            'maps' => ['mimes:jpg,jpeg,png,gif,webp,svg', 'max:10240'],
            'popups' => ['mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,mov', 'max:10240'],
            'seo' => ['mimes:jpg,jpeg,png,gif,webp,svg', 'max:10240'],
            'videos' => ['mimes:mp4,webm,ogg,mov,avi', 'max:51200'],
            'signatures' => ['mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'],
        ];

        $categoryRules = $rules[$category] ?? $rules['general'];
        $mime = $file->getMimeType() ?? '';
        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/')) {
            $limit = str_starts_with($mime, 'video/')
                ? config('fileupload.max_video_size', 102400)
                : config('fileupload.max_image_size', 51200);
            $categoryRules = array_filter($categoryRules, fn ($rule) => !str_starts_with($rule, 'max:'));
            $categoryRules[] = 'max:' . $limit;
        }

        $validator = Validator::make(['file' => $file], [
            'file' => array_merge(['required', 'file'], $categoryRules)
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        if ($mime === 'image/svg+xml') {
            $xml = file_get_contents($file->getRealPath());
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $valid = !str_contains(strtoupper($xml), '<!DOCTYPE')
                && $document->loadXML($xml, LIBXML_NONET)
                && $document->documentElement?->localName === 'svg';
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($valid) {
                foreach ($document->getElementsByTagName('*') as $element) {
                    if (in_array(strtolower($element->localName), ['script', 'foreignobject', 'iframe', 'object', 'embed', 'animate', 'set'], true)) {
                        $valid = false;
                    }
                    foreach ($element->attributes as $attribute) {
                        if (str_starts_with(strtolower($attribute->localName), 'on')
                            || ($attribute->localName === 'href' && !str_starts_with($attribute->value, '#'))
                            || preg_match('/(?:javascript:|@import|url\(\s*[\'"]?[^#])/i', $attribute->value)) {
                            $valid = false;
                        }
                    }
                    if ($element->localName === 'style' && preg_match('/(?:@import|url\(\s*[\'"]?[^#])/i', $element->textContent)) {
                        $valid = false;
                    }
                }
            }
            if (!$valid) {
                throw ValidationException::withMessages(['file' => 'SVG must be a valid image without scripts or external content.']);
            }
        }
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $dimensions = @getimagesize($file->getRealPath());
            if (!$dimensions || $dimensions[0] * $dimensions[1] > 40000000) {
                throw ValidationException::withMessages(['file' => 'Image must be valid and contain at most 40 megapixels.']);
            }
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
            // Raster processing is shared with direct photo upload endpoints.
            $width = $request->integer('width') ?: config('fileupload.image_processing.max_dimensions.width', 2560);
            $height = $request->integer('height') ?: config('fileupload.image_processing.max_dimensions.height', 2560);
            $quality = $request->integer('quality') ?: config('fileupload.image_processing.default_quality', 80);

            $processed = app(MediaImageProcessor::class)->process($file, $width, $height, $quality);
            $dimensions = ['width' => $processed['width'], 'height' => $processed['height']];
            $convertedPath = preg_replace('/\.[^.]+$/', '.' . $processed['extension'], $path);
            $imageData = $processed['bytes'];
            $this->storeFile($convertedPath, $imageData);
        } else {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to read uploaded file.');
            }

            try {
                if (!Storage::disk($this->storageDisk())->writeStream($path, $stream)) {
                    throw new \RuntimeException("File upload failed for {$path}");
                }
            } finally {
                fclose($stream);
            }
        }

        return [
            'dimensions' => $dimensions,
            'converted_path' => $convertedPath,
            'size' => $isImage ? strlen($imageData) : $file->getSize(),
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
        $thumbnailFileName = 'thumb_' . pathinfo($originalFileName, PATHINFO_FILENAME) . '.webp';
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

}
