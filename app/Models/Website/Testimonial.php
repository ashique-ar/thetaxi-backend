<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Testimonial extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'position',
        'company',
        'content',
        'rating',
        'image',
        'location',
        'is_featured',
        'is_active',
        'sort_order',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'rating' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Get active testimonials
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get featured testimonials
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Order by sort order and created date
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')
                    ->orderBy('created_at', 'desc');
    }

    /**
     * Get rating stars as array
     */
    public function getRatingStarsAttribute()
    {
        $stars = [];
        for ($i = 1; $i <= 5; $i++) {
            $stars[] = $i <= $this->rating ? 'filled' : 'empty';
        }
        return $stars;
    }

    /**
     * Get excerpt of content
     */
    public function getExcerptAttribute()
    {
        return Str::limit($this->content, 150);
    }
}