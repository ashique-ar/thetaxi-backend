<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Traits\RecordsBookingHistoryAtomically;

class CorporateTransportGenerationLog extends BaseModel
{
    use RecordsBookingHistoryAtomically;

    protected $table = 'corporate_transport_generation_logs';

    protected $fillable = [
        'program_id',
        'route_id',
        'shift_id',
        'service_date',
        'direction',
        'booking_id',
        'status',
        'included_count',
        'message',
        'context',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'service_date' => 'date',
        'included_count' => 'integer',
        'context' => 'array',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function program()
    {
        return $this->belongsTo(CorporateTransportProgram::class, 'program_id');
    }
}
