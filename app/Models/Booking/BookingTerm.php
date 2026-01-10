<?php

namespace App\Models\Booking;

use App\Models\BaseModel;

class BookingTerm extends BaseModel
{
    protected $table = 'booking_terms';

    protected $fillable = [
        'booking_id',
        'terms_and_condition_id',
        'terms_version',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    public function terms()
    {
        return $this->belongsTo(\App\Models\TermsAndCondition::class, 'terms_and_condition_id');
    }

    public function booking()
    {
        return $this->belongsTo(\App\Models\Booking\Booking::class, 'booking_id');
    }
}
