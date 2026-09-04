<?php

namespace App\Models\Sales;

use App\Models\BaseModel;

class SalesCommissionBusinessCalendarDate extends BaseModel
{
    protected $fillable = ['calendar_id', 'calendar_date', 'day_type', 'name', 'created_by'];

    protected $casts = ['calendar_date' => 'date'];
}
