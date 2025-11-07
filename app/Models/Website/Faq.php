<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Faq extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'question',
        'answer',
        'category',
        'sort_order',
        'is_active',
        'is_featured'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * Scope for active FAQs
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for featured FAQs
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope for ordered FAQs
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('created_at', 'asc');
    }

    /**
     * Scope by category
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Get answer excerpt
     */
    public function getAnswerExcerptAttribute(): string
    {
        return Str::limit(strip_tags($this->answer), 100);
    }
}