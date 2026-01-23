<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryServicePageSection extends BaseModel
{
    protected $table = 'inquiry_service_page_sections';

    protected $fillable = [
        'inquiry_service_page_id',
        'type',
        'data',
        'sort_order',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'data' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(InquiryServicePage::class, 'inquiry_service_page_id');
    }
}
