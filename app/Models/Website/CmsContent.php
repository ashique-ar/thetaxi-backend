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
 * @property string|null $author
 * @property string|null $thumbnail
 * @property string|null $body
 * @property \Carbon\Carbon|null $published_at
 * @property string $status
 * @property string|null $excerpt
 * @property array|null $custom_fields
 * @property string|null $featured_image
 * @property array|null $gallery_images
 * @property int $views_count
 * @property bool $is_featured
 * @property bool $allow_comments
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
        'published_at',
        'status',
        'excerpt',
        'custom_fields',
        'featured_image',
        'gallery_images',
        'views_count',
        'is_featured',
        'allow_comments',
        'is_ai_generated',
        'meta_title',
        'meta_description',
        'meta_tags',
        'is_active',
        'display_order',
        'url',
        'created_user_id',
        'updated_user_id',
        // Travel-specific fields
        'price',
        'price_currency',
        'duration',
        'location',
        'category',
        'difficulty_level',
        'rating',
        'reviews_count',
        'coordinates',
        'tags',
        'availability_status',
        'special_offer',
        'discount_percentage',
        // Booking fields
        'pickup_location',
        'dropoff_location',
        'service_type',
        'min_days',
        'pickup_lat',
        'pickup_lng',
        'dropoff_lat',
        'dropoff_lng',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'published_at' => 'datetime',
        'custom_fields' => 'array',
        'gallery_images' => 'array',
        'views_count' => 'integer',
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'is_ai_generated' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        // Travel-specific casts
        'price' => 'decimal:2',
        'rating' => 'decimal:1',
        'reviews_count' => 'integer',
        'coordinates' => 'array',
        'tags' => 'array',
        'special_offer' => 'boolean',
        'discount_percentage' => 'integer',
        'min_days' => 'integer',
        'pickup_lat' => 'decimal:8',
        'pickup_lng' => 'decimal:8',
        'dropoff_lat' => 'decimal:8',
        'dropoff_lng' => 'decimal:8',
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

    /**
     * Scope to get published content only.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope to get featured content.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope to filter by content type.
     */
    public function scopeByType($query, $typeSlug)
    {
        return $query->whereHas('contentType', function ($q) use ($typeSlug) {
            $q->where('slug', $typeSlug);
        });
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by location.
     */
    public function scopeByLocation($query, $location)
    {
        return $query->where('location', 'like', "%{$location}%");
    }

    /**
     * Scope to get content with special offers.
     */
    public function scopeSpecialOffers($query)
    {
        return $query->where('special_offer', true)
            ->where('discount_percentage', '>', 0);
    }

    /**
     * Scope to order by rating.
     */
    public function scopeByRating($query, $order = 'desc')
    {
        return $query->orderBy('rating', $order);
    }

    /**
     * Scope to order by price.
     */
    public function scopeByPrice($query, $order = 'asc')
    {
        return $query->orderBy('price', $order);
    }

    /**
     * Get the formatted price.
     */
    public function getFormattedPriceAttribute()
    {
        if (!$this->price) {
            return null;
        }

        return $this->price_currency . ' ' . number_format(floor(max(0, $this->price)), 0);
    }

    /**
     * Get star rating as array.
     */
    public function getStarRatingAttribute()
    {
        $stars = [];
        for ($i = 1; $i <= 5; $i++) {
            $stars[] = $i <= $this->rating;
        }
        return $stars;
    }

    /**
     * Get tags as comma-separated string.
     */
    public function getTagsStringAttribute()
    {
        return $this->tags ? implode(', ', $this->tags) : '';
    }

    /**
     * Increment views count.
     */
    public function incrementViews()
    {
        $this->increment('views_count');
    }
}
