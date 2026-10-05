<?php

namespace App\Models\Hr;

use App\Models\NonSoftDeletableModel;
use LogicException;
use Spatie\Activitylog\Support\LogOptions;

class PayrollInputFact extends NonSoftDeletableModel
{
    protected $table='hr_payroll_input_facts'; protected $guarded=[]; protected $casts=['effective_date'=>'date','quantity_units'=>'decimal:6','source_snapshot'=>'array'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['fact_kind', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('PayrollInputFact');
    }

    protected static function booted():void{static::updating(fn()=>throw new LogicException('Payroll input facts are append-only.'));static::deleting(fn()=>throw new LogicException('Payroll input facts cannot be deleted.'));}
}
