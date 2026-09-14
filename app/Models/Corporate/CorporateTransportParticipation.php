<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;

class CorporateTransportParticipation extends BaseModel
{
    public const STATUS_INCLUDED = 'included';
    public const STATUS_OPTED_OUT = 'opted_out';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_COORDINATOR_INCLUDED = 'coordinator_included';
    public const STATUS_COORDINATOR_EXCLUDED = 'coordinator_excluded';
    public const STATUS_NO_SHOW = 'no_show';

    protected $table = 'corporate_transport_participations';

    protected $fillable = [
        'program_id',
        'route_id',
        'shift_id',
        'corporate_employee_id',
        'service_date',
        'direction',
        'status',
        'booking_id',
        'booking_item_id',
        'booking_stop_id',
        'frozen_at',
        'changed_by_user_id',
        'reason',
        'metadata',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'service_date' => 'date',
        'frozen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function setServiceDateAttribute($value): void
    {
        $this->attributes['service_date'] = \Carbon\Carbon::parse($value)->toDateString();
    }

    public function driverStops()
    {
        return $this->hasMany(\App\Models\DriverAssignmentStop::class, 'booking_stop_id', 'booking_stop_id');
    }

    public function employee()
    {
        return $this->belongsTo(CorporateEmployee::class, 'corporate_employee_id');
    }

    public function route()
    {
        return $this->belongsTo(CorporateTransportRoute::class, 'route_id');
    }

    public function shift()
    {
        return $this->belongsTo(CorporateTransportShift::class, 'shift_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function bookingItem()
    {
        return $this->belongsTo(BookingItem::class, 'booking_item_id');
    }
}
