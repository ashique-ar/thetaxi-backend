<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FAQ extends BaseModel
{
    use SoftDeletes;

    protected $table = 'faqs';

    protected $fillable = [
        'faq_category_id',
        'question',
        'answer',
        'sort_order',
        'is_featured',
        'is_active',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(FAQCategory::class, 'faq_category_id');
    }

    public static function active()
    {
        return static::where('is_active', true)->orderBy('sort_order');
    }

    public static function featured()
    {
        return static::where('is_featured', true)->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('faq_category_id', $categoryId);
    }

    public function scopeSearch($query, $search)
    {
        return $query->whereFullText(['question', 'answer'], $search);
    }
}
