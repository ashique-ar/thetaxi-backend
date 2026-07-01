<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingGeneratedReport extends BaseModel
{
    protected $table = 'booking_generated_reports';

    protected $fillable = [
        'name',
        'report_type',
        'template_id',
        'format',
        'status',
        'filters',
        'metadata',
        'row_count',
        'size',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'filters' => 'array',
        'metadata' => 'array',
        'row_count' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
