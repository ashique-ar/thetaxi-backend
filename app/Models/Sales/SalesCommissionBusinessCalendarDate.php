<?php

namespace App\Models\Sales;

use App\Models\NonSoftDeletableModel;

class SalesCommissionBusinessCalendarDate extends NonSoftDeletableModel
{
    protected $fillable = ['calendar_id', 'calendar_date', 'day_type', 'name', 'created_by'];

    protected $casts = ['calendar_date' => 'date'];
}
