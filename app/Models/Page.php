<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Page extends BaseModel
{
    use HasUuids, SoftDeletes, LogsActivity;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'excerpt',
        'featured_image',
        'template',
        'parent_id',
        'status',
        'visibility',
        'password',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'custom_fields',
        'sort_order',
        'is_homepage',
        'is_in_menu',
        'is_featured',
        'published_at',
        'created_user_id',
        'updated_user_id'
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'is_homepage' => 'boolean',
        'is_in_menu' => 'boolean',
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $attributes = [
        'status' => 'draft',
        'visibility' => 'public',
        'sort_order' => 0,
        'is_homepage' => false,
        'is_in_menu' => false,
        'is_featured' => false
    ];

    /**
     * Activity log options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'title', 'slug', 'status', 'visibility', 
                'is_homepage', 'is_in_menu', 'is_featured',
                'published_at'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Relationships
     */
    
    /**
     * Parent page relationship
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    /**
     * Child pages relationship
     */
    public function children(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * User who created the page
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * User who last updated the page
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Scopes
     */
    
    /**
     * Scope for published pages
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                    ->where('is_active', true)
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', now());
    }

    /**
     * Scope for public pages
     */
    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    /**
     * Scope for featured pages
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope for menu pages
     */
    public function scopeInMenu($query)
    {
        return $query->where('is_in_menu', true);
    }

    /**
     * Scope for top-level pages (no parent)
     */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope for ordering pages
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    /**
     * Accessors & Mutators
     */
    
    /**
     * Get the page URL
     */
    public function getUrlAttribute(): string
    {
        if ($this->is_homepage) {
            return '/';
        }
        
        return '/' . $this->slug;
    }

    /**
     * Get the full URL with domain
     */
    public function getFullUrlAttribute(): string
    {
        return url($this->url);
    }

    /**
     * Set the slug attribute
     */
    public function setSlugAttribute($value)
    {
        $this->attributes['slug'] = \Illuminate\Support\Str::slug($value);
    }

    /**
     * Set published status and date
     */
    public function publish(): bool
    {
        $this->status = 'published';
        $this->published_at = $this->published_at ?: now();
        
        return $this->save();
    }

    /**
     * Set draft status
     */
    public function unpublish(): bool
    {
        $this->status = 'draft';
        
        return $this->save();
    }

    /**
     * Check if page is published
     */
    public function isPublished(): bool
    {
        return $this->status === 'published' 
               && $this->is_active 
               && $this->published_at 
               && $this->published_at <= now();
    }

    /**
     * Check if page is accessible to public
     */
    public function isPubliclyAccessible(): bool
    {
        return $this->isPublished() && $this->visibility === 'public';
    }

    /**
     * Get page hierarchy level
     */
    public function getHierarchyLevel(): int
    {
        $level = 0;
        $parent = $this->parent;
        
        while ($parent) {
            $level++;
            $parent = $parent->parent;
        }
        
        return $level;
    }

    /**
     * Get page breadcrumb
     */
    public function getBreadcrumb(): array
    {
        $breadcrumb = [];
        $current = $this;
        
        while ($current) {
            array_unshift($breadcrumb, [
                'title' => $current->title,
                'url' => $current->url,
                'slug' => $current->slug
            ]);
            $current = $current->parent;
        }
        
        return $breadcrumb;
    }

    /**
     * Generate SEO meta data
     */
    public function getSeoMeta(): array
    {
        return [
            'title' => $this->seo_title ?: $this->title,
            'description' => $this->seo_description ?: $this->excerpt,
            'keywords' => $this->seo_keywords,
            'canonical' => $this->full_url,
            'og_title' => $this->seo_title ?: $this->title,
            'og_description' => $this->seo_description ?: $this->excerpt,
            'og_image' => $this->featured_image,
            'og_url' => $this->full_url,
            'twitter_card' => 'summary_large_image',
            'twitter_title' => $this->seo_title ?: $this->title,
            'twitter_description' => $this->seo_description ?: $this->excerpt,
            'twitter_image' => $this->featured_image
        ];
    }

    /**
     * Get page statistics
     */
    public function getStats(): array
    {
        return [
            'view_count' => $this->page_views ?? 0,
            'word_count' => str_word_count(strip_tags($this->content)),
            'character_count' => strlen(strip_tags($this->content)),
            'reading_time' => ceil(str_word_count(strip_tags($this->content)) / 200), // 200 WPM average
            'last_modified' => $this->updated_at->diffForHumans(),
            'hierarchy_level' => $this->getHierarchyLevel(),
            'child_count' => $this->children()->count()
        ];
    }
}