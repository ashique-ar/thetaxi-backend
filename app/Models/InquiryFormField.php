<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryFormField extends BaseModel
{
    protected $fillable = [
        'inquiry_form_id',
        'name',
        'label',
        'type',
        'icon',
        'placeholder',
        'help_text',
        'is_required',
        'validation_rules',
        'options',
        'default_value',
        'width',
        'sort_order',
        'conditional_logic',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'options' => 'array',
        'conditional_logic' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(InquiryForm::class, 'inquiry_form_id');
    }
}
