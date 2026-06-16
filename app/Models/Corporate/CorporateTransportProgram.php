<?php

namespace App\Models\Corporate;

use App\Models\BaseModel;

class CorporateTransportProgram extends BaseModel
{
    protected $table = 'corporate_transport_programs';

    protected $fillable = [
        'corporate_id',
        'name',
        'status',
        'timezone',
        'default_opt_mode',
        'cutoff_minutes_before',
        'start_date',
        'end_date',
        'description',
        'settings',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'settings' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'cutoff_minutes_before' => 'integer',
    ];

    public function corporate()
    {
        return $this->belongsTo(Corporate::class, 'corporate_id');
    }

    public function shifts()
    {
        return $this->hasMany(CorporateTransportShift::class, 'program_id');
    }

    public function routes()
    {
        return $this->hasMany(CorporateTransportRoute::class, 'program_id');
    }
}
