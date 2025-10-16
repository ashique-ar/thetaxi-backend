<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use App\Traits\UUID;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CMS content model.
 * 
 * @property string $id
 * @property string $cms_content_type_id
 * @property string|null $title
 * @property string $slug
 * @property string $author
 * @property string $thumbnail
 * @property string|null $body
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_tags
 * @property bool $is_active
 * @property int|null $display_order
 * @property string|null $url
 * @property string|null $created_user_id
 * @property string|null $updated_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * 
 * @property-read CmsContentType $contentType
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 */
class CmsContent extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cms_content_type_id',
        'title',
        'slug',
        'author',
        'thumbnail',
        'body',
        'meta_title',
        'meta_description',
        'meta_tags',
        'is_active',
        'display_order',
        'url',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the content type for this CMS content.
     */
    public function contentType(): BelongsTo
    {
        return $this->belongsTo(CmsContentType::class, 'cms_content_type_id');
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
}
