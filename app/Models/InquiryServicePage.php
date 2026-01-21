<?php

namespace App\Models;

use App\Models\Service\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InquiryServicePage extends BaseModel
{
    protected $fillable = [
        'service_type_id',
        'inquiry_form_id',
        'name',
        'slug',
        'code',
        'inquiry_type',
        'status',
        'content',
        'settings',
        'seo_title',
        'seo_description',
        'seo_og_image',
        'seo_keywords',
        'sort_order',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'content' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class, 'service_type_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(InquiryForm::class, 'inquiry_form_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class, 'inquiry_service_page_id');
    }
}
