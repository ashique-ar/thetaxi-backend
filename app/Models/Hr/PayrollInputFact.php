<?php

namespace App\Models\Hr;

use App\Models\NonSoftDeletableModel;
use Illuminate\Support\Facades\DB;
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

    protected static function booted():void
    {
        static::creating(function (self $fact): void {
            $company = DB::table('companies')->where('id', $fact->company_id)->where('is_active', true)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 409, 'Payroll input facts require an active legal entity.');
            $staff = DB::table('staff')->where('id', $fact->staff_id)->where('company_id', $fact->company_id)
                ->lockForUpdate()->first();
            abort_unless($staff, 422, 'Payroll fact Staff does not belong to its legal entity.');
        });
        static::updating(fn()=>throw new LogicException('Payroll input facts are append-only.'));
        static::deleting(fn()=>throw new LogicException('Payroll input facts cannot be deleted.'));
    }
}
