<?php

namespace App\Models\Hr;

use App\Models\BaseModel;
use LogicException;

class PayrollInputFact extends BaseModel
{
    protected $table='hr_payroll_input_facts'; protected $guarded=[]; protected $casts=['effective_date'=>'date','quantity_units'=>'decimal:6','source_snapshot'=>'array'];
    protected static function booted():void{static::updating(fn()=>throw new LogicException('Payroll input facts are append-only.'));static::deleting(fn()=>throw new LogicException('Payroll input facts cannot be deleted.'));}
}
