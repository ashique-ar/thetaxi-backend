<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FAQCategory extends BaseModel
{
    use SoftDeletes;

    protected $table = 'faq_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function faqs(): HasMany
    {
        return $this->hasMany(FAQ::class, 'faq_category_id');
    }

    public function activeFaqs(): HasMany
    {
        return $this->hasMany(FAQ::class, 'faq_category_id')->where('is_active', true)->orderBy('sort_order');
    }

    public static function active()
    {
        return static::where('is_active', true)->orderBy('sort_order');
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
