<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\UUID;
use Illuminate\Support\Facades\Storage;

/**
 * Image Gallery Model
 * 
 * Represents images stored in the system gallery. Used for storing and managing
 * various images such as vehicle photos, promotional images, and other visual content.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $title Image title or name
 * @property string|null $caption Descriptive caption for the image
 * @property string $path File path to the full-size image
 * @property string|null $thumbnail_path File path to the thumbnail version
 * @property int $sort_order Display order for sorting images
 * @property bool $is_active Whether image is active and visible
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $user_id User who owns this record
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class ImageGallery extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'image_galleries';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'title',
        'caption',
        'path',
        'thumbnail_path',
        'sort_order',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who created this record.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (ImageGallery $image): void {
            Storage::disk(config('filesystems.default'))->delete(array_filter([$image->path, $image->thumbnail_path]));
        });
    }
}
