<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;

class CorporateTransportShift extends BaseModel
{
    protected $table = 'corporate_transport_shifts';

    protected $fillable = [
        'program_id',
        'name',
        'pickup_time',
        'dropoff_time',
        'operating_days',
        'cutoff_minutes_before',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'operating_days' => 'array',
        'cutoff_minutes_before' => 'integer',
        'is_active' => 'boolean',
    ];

    public function program()
    {
        return $this->belongsTo(CorporateTransportProgram::class, 'program_id');
    }

    public function members()
    {
        return $this->hasMany(CorporateTransportRouteMember::class, 'shift_id');
    }
}
