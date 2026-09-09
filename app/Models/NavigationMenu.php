<?php

namespace App\Models;

use App\Traits\HasIsActive;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationMenu extends BaseModel
{
    use HasIsActive;

    protected $fillable = [
        'company_id',
        'parent_id',
        'title',
        'url',
        'route_name',
        'route_params',
        'target',
        'icon',
        'description',
        'sort_order',
        'is_active',
        'show_in_header',
        'show_in_footer',
        'permissions'
    ];

    protected $casts = [
        'route_params' => 'array',
        'permissions' => 'array',
        'is_active' => 'boolean',
        'show_in_header' => 'boolean',
        'show_in_footer' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Get the parent menu item
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(NavigationMenu::class, 'parent_id');
    }

    /**
     * Get child menu items
     */
    public function children(): HasMany
    {
        return $this->hasMany(NavigationMenu::class, 'parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Get all descendants
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Check if menu item has children
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Scope for header navigation
     */
    public function scopeHeaderNavigation($query)
    {
        return $query->where('show_in_header', true)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    /**
     * Scope for footer navigation
     */
    public function scopeFooterNavigation($query)
    {
        return $query->where('show_in_footer', true)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Get the full URL for this menu item
     */
    public function getFullUrlAttribute(): ?string
    {
        if ($this->url) {
            return $this->url;
        }

        if ($this->route_name) {
            return route($this->route_name, $this->route_params ?? []);
        }

        return null;
    }

    /**
     * Check if menu item is external link
     */
    public function isExternal(): bool
    {
        return $this->url && (
            str_starts_with($this->url, 'http://') ||
            str_starts_with($this->url, 'https://') ||
            str_starts_with($this->url, '//')
        );
    }
}
