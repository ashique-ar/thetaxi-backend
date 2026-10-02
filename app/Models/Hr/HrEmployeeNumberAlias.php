<?php

namespace App\Models\Hr;

use App\Models\BaseModel;

/**
 * Historical/alternate record of every employee number ever issued to a
 * Staff member — one row per (employee_number) ever assigned, whether it
 * was allocated from {@see HrEmployeeNumberSequence}'s counter
 * (`alias_type` = 'canonical') or set manually
 * (`alias_type` = 'manual_canonical'), with `is_canonical` marking the
 * currently-active number and `effective_from`/`effective_until` bounding
 * each assignment.
 *
 * {@see \App\Services\Hr\EmployeeNumberAllocator} both writes this table
 * (via reserveAlias()) and reads it to guarantee an employee number, once
 * issued to a Staff member, is never recycled to a different one — even
 * after that Staff member is soft-deleted or their number changes.
 */
class HrEmployeeNumberAlias extends BaseModel
{
    protected $fillable = ['staff_id', 'company_id', 'employee_number', 'alias_type', 'is_canonical', 'effective_from', 'effective_until', 'reason', 'approved_by'];

    protected $casts = ['is_canonical' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
}
