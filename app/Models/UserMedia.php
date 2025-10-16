<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\UUID;

/**
 * User Media Model
 * 
 * Centralized model for handling all file uploads in the system.
 * Supports both local and S3 storage with comprehensive metadata tracking.
 * 
 * @property string $id Primary key (UUID)
 * @property string $file_name Generated unique filename
 * @property string $original_name Original filename from upload
 * @property string $file_path Directory path where file is stored
 * @property string $full_path Complete path including filename
 * @property string $mime_type File MIME type
 * @property int $size File size in bytes
 * @property int|null $width Image width (for images only)
 * @property int|null $height Image height (for images only)
 * @property string|null $user_id ID of user who owns this file
 * @property string|null $profile_id ID of profile associated with this file
 * @property string $category File category (vehicles, gallery, documents, etc.)
 * @property bool $is_image Whether this file is an image
 * @property bool $is_thumbnail Whether this is a thumbnail version
 * @property string|null $parent_id Parent file ID (for thumbnails)
 * @property string $storage_type Storage type (local, s3)
 * @property array|null $metadata Additional metadata as JSON
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $user User who owns this file
 * @property-read UserMedia|null $parent Parent file (for thumbnails)
 * @property-read UserMedia[] $thumbnails Thumbnail versions of this file
 */
class UserMedia extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'user_media';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'file_name',
        'original_name',
        'file_path',
        'full_path',
        'mime_type',
        'size',
        'width',
        'height',
        'user_id',
        'category',
        'is_image',
        'is_thumbnail',
        'parent_id',
        'storage_type',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'is_image' => 'boolean',
        'is_thumbnail' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Available file categories
     */
    public const CATEGORIES = [
        'general' => 'General Files',
        'vehicles' => 'Vehicle Images',
        'gallery' => 'Gallery Images',
        'documents' => 'Documents',
        'avatars' => 'User Avatars',
        'thumbnails' => 'Thumbnails',
    ];

    /**
     * Available storage types
     */
    public const STORAGE_TYPES = [
        'local' => 'Local Storage',
        's3' => 'Amazon S3',
    ];

    // ========== RELATIONSHIPS ==========

    /**
     * Get the user who owns this file.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the parent file (for thumbnails).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(UserMedia::class, 'parent_id');
    }

    /**
     * Get thumbnail versions of this file.
     */
    public function thumbnails(): HasMany
    {
        return $this->hasMany(UserMedia::class, 'parent_id')->where('is_thumbnail', true);
    }

    // ========== SCOPES ==========

    /**
     * Scope to get only images
     */
    public function scopeImages($query)
    {
        return $query->where('is_image', true);
    }

    /**
     * Scope to get only documents
     */
    public function scopeDocuments($query)
    {
        return $query->where('is_image', false);
    }

    /**
     * Scope to get files by category
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to get files by storage type
     */
    public function scopeByStorageType($query, string $storageType)
    {
        return $query->where('storage_type', $storageType);
    }

    /**
     * Scope to exclude thumbnails
     */
    public function scopeMainFiles($query)
    {
        return $query->where('is_thumbnail', false);
    }

    /**
     * Scope to get only thumbnails
     */
    public function scopeThumbnails($query)
    {
        return $query->where('is_thumbnail', true);
    }

    // ========== ACCESSORS & MUTATORS ==========

    /**
     * Get the file URL based on storage type
     */
    public function getUrlAttribute(): string
    {
        if ($this->storage_type === 's3') {
            return \Storage::disk('s3')->url($this->full_path);
        } else {
            return \Storage::disk('public')->url($this->full_path);
        }
    }

    /**
     * Get human readable file size
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get file extension
     */
    public function getExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Check if file exists in storage
     */
    public function getExistsAttribute(): bool
    {
        if ($this->storage_type === 's3') {
            return \Storage::disk('s3')->exists($this->full_path);
        } else {
            return \Storage::disk('public')->exists($this->full_path);
        }
    }

    // ========== METHODS ==========

    /**
     * Get the primary thumbnail for this file
     */
    public function getPrimaryThumbnail(): ?UserMedia
    {
        return $this->thumbnails()->first();
    }

    /**
     * Check if this file is owned by the given user
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Get file content
     */
    public function getContent(): string
    {
        if ($this->storage_type === 's3') {
            return \Storage::disk('s3')->get($this->full_path);
        } else {
            return \Storage::disk('public')->get($this->full_path);
        }
    }

    /**
     * Delete file from storage
     */
    public function deleteFromStorage(): bool
    {
        try {
            if ($this->storage_type === 's3') {
                \Storage::disk('s3')->delete($this->full_path);
            } else {
                \Storage::disk('public')->delete($this->full_path);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Boot method - automatically delete file from storage when model is deleted
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($media) {
            // Delete thumbnails first
            $media->thumbnails()->each(function ($thumbnail) {
                $thumbnail->deleteFromStorage();
                $thumbnail->delete();
            });

            // Delete the main file from storage
            $media->deleteFromStorage();
        });
    }
}
