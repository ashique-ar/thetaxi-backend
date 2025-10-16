<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the file upload system. Set to 's3' to use Amazon S3, or 'local'
    | for local storage.
    |
    */

    'default_disk' => env('FILE_UPLOAD_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    |
    | Configure the base paths for different file categories. These paths
    | will be used as the base directory structure for organizing files.
    |
    */

    'paths' => [
        'general' => 'media/general',
        'vehicles' => 'media/vehicles',
        'gallery' => 'media/gallery/images',
        'documents' => 'media/documents',
        'avatars' => 'media/avatars',
        'thumbnails' => 'media/thumbnails',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Size Limits (in KB)
    |--------------------------------------------------------------------------
    |
    | Set maximum file sizes for different categories. These limits will be
    | enforced during file validation.
    |
    */

    'size_limits' => [
        'general' => 10240,      // 10MB
        'vehicles' => 5120,      // 5MB
        'gallery' => 10240,      // 10MB
        'documents' => 20480,    // 20MB
        'avatars' => 2048,       // 2MB
        'thumbnails' => 1024,    // 1MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed File Types
    |--------------------------------------------------------------------------
    |
    | Define which file types are allowed for each category. Use MIME types
    | or file extensions.
    |
    */

    'allowed_types' => [
        'general' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain'
        ],
        'vehicles' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ],
        'gallery' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ],
        'documents' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/rtf'
        ],
        'avatars' => [
            'image/jpeg',
            'image/png',
            'image/gif'
        ],
        'thumbnails' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    |
    | Configure default settings for image processing using Intervention Image.
    |
    */

    'image_processing' => [
        'default_quality' => 90,
        'convert_to_webp' => env('CONVERT_IMAGES_TO_WEBP', true),
        'thumbnail_size' => [150, 150],
        'max_dimensions' => [
            'width' => 4096,
            'height' => 4096,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Configuration
    |--------------------------------------------------------------------------
    |
    | Additional S3-specific settings for file uploads.
    |
    */

    's3' => [
        'delete_local_after_s3_upload' => env('DELETE_LOCAL_AFTER_S3_UPLOAD', true),
        'public_read' => env('S3_PUBLIC_READ', true),
        'cache_control' => 'public, max-age=31536000', // 1 year
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Configure security-related settings for file uploads.
    |
    */

    'security' => [
        'scan_for_viruses' => env('SCAN_UPLOADS_FOR_VIRUSES', false),
        'check_file_contents' => true,
        'allowed_extensions' => [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'txt',
            'rtf'
        ],
        'blocked_extensions' => [
            'exe',
            'bat',
            'cmd',
            'com',
            'pif',
            'scr',
            'vbs',
            'js',
            'jar',
            'php',
            'asp'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup Settings
    |--------------------------------------------------------------------------
    |
    | Configure automatic cleanup of old or orphaned files.
    |
    */

    'cleanup' => [
        'delete_orphaned_files' => env('DELETE_ORPHANED_FILES', false),
        'orphaned_file_age_days' => 30,
        'temp_file_age_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | URL Generation
    |--------------------------------------------------------------------------
    |
    | Settings for generating file URLs.
    |
    */

    'urls' => [
        'use_signed_urls' => env('USE_SIGNED_FILE_URLS', false),
        'signed_url_expires' => 60, // minutes
        'cdn_url' => env('FILE_CDN_URL', null),
    ],

];
