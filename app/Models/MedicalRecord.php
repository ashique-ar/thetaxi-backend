<?php

namespace App\Models;

use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MedicalRecord extends BaseModel
{
    protected $table = 'medical_records';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'medical_category_id',
        'title',
        'description',
        'record_number',
        'issued_date',
        'valid_until',
        'issuing_authority',
        'status',
        'status_reason',
        'status_updated_by',
        'status_updated_at',
        'created_by',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'valid_until' => 'date',
        'status_updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MedicalCategory::class, 'medical_category_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }
}
