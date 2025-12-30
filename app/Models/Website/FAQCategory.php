<?php

namespace App\Models\Website;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FAQCategory extends BaseModel
{
    use SoftDeletes;

    protected $table = 'f_a_q_categories';

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
        return $this->hasMany(FAQ::class);
    }

    public function activeFaqs(): HasMany
    {
        return $this->hasMany(FAQ::class)->where('is_active', true)->orderBy('sort_order');
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
