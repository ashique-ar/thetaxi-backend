<?php

namespace App\Models;

use App\Traits\HasIsActive;

class FooterLink extends BaseModel
{
    use HasIsActive;

    protected $fillable = [
        'footer_group_id',
        'title',
        'url',
        'route_name',
        'route_params',
        'target',
        'icon',
        'description',
        'footer_section',
        'sort_order',
        'is_active',
        'additional_attributes'
    ];

    protected $casts = [
        'route_params' => 'array',
        'additional_attributes' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Available footer sections
     */
    public const FOOTER_SECTIONS = [
        'links' => 'Links',
        'social' => 'Social Media',
        'legal' => 'Legal',
        'contact' => 'Contact Information'
    ];

    /**
     * Scope for specific footer section
     */
    public function scopeBySection($query, string $section)
    {
        return $query->where('footer_section', $section)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Scope for social media links
     */
    public function scopeSocial($query)
    {
        return $this->scopeBySection($query, 'social');
    }

    /**
     * Scope for legal links
     */
    public function scopeLegal($query)
    {
        return $this->scopeBySection($query, 'legal');
    }

    /**
     * Scope for contact links
     */
    public function scopeContact($query)
    {
        return $this->scopeBySection($query, 'contact');
    }

    /**
     * Get the full URL for this footer link
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
     * Check if footer link is external
     */
    public function isExternal(): bool
    {
        return $this->url && (
            str_starts_with($this->url, 'http://') ||
            str_starts_with($this->url, 'https://') ||
            str_starts_with($this->url, '//')
        );
    }

    /**
     * Check if this is a social media link
     */
    public function isSocial(): bool
    {
        return $this->footer_section === 'social';
    }

    /**
     * Get formatted attributes for rendering
     */
    public function getFormattedAttributesAttribute(): array
    {
        $attributes = $this->additional_attributes ?? [];
        
        if ($this->isSocial()) {
            $attributes['rel'] = $attributes['rel'] ?? 'noopener noreferrer';
            $attributes['target'] = $this->target;
        }

        return $attributes;
    }
}
