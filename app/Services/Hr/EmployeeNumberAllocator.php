<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployeeNumberAlias;
use App\Models\Hr\HrEmployeeNumberSequence;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

class EmployeeNumberAllocator
{
    public function allocate(Staff $staff, string $actorUserId, ?string $manualNumber = null, ?string $reason = null): string
    {
        abort_unless($staff->company_id, 422, 'A legal entity is required before allocating an employee number.');
        return DB::transaction(function () use ($staff, $actorUserId, $manualNumber, $reason) {
            abort_unless(DB::table('companies')->where('id', $staff->company_id)->lockForUpdate()->first(), 422, 'Employee legal entity does not exist.');
            if ($manualNumber) {
                abort_unless($reason, 422, 'A reason is required for manual employee-number override.');
                abort_if(Staff::withTrashed()->where('code', $manualNumber)->where('id', '!=', $staff->id)->exists() || HrEmployeeNumberAlias::query()->where('employee_number', $manualNumber)->where('staff_id','!=',$staff->id)->exists(), 409, 'Employee number was already or formerly assigned and cannot be recycled.');
                if ($staff->code !== $manualNumber) $staff->update(['code' => $manualNumber, 'updated_user_id' => $actorUserId]);
                return $this->reserveAlias($staff, $manualNumber, 'manual_canonical', $actorUserId, $reason);
            }
            if ($staff->code) return $this->reserveAlias($staff, $staff->code, 'canonical', $actorUserId, $reason);
            $sequence = HrEmployeeNumberSequence::query()->where('company_id', $staff->company_id)->lockForUpdate()->first();
            if (! $sequence) $sequence = HrEmployeeNumberSequence::create(['company_id'=>$staff->company_id,'template'=>'EMP-{NNNN}','start_value'=>1001,'next_value'=>1001,'padding'=>4,'status'=>'active','version'=>1,'updated_user_id'=>$actorUserId]);
            abort_unless($sequence->status === 'active', 422, 'Employee-number sequence is not active.');
            do {
                $value = $sequence->next_value;
                $number = preg_replace_callback('/\{N+\}/', fn ($match) => str_pad((string) $value, max($sequence->padding, strlen($match[0]) - 2), '0', STR_PAD_LEFT), $sequence->template);
                $number = (string) $sequence->prefix.$number.(string) $sequence->suffix;
                $sequence->update(['next_value'=>$value+1,'version'=>$sequence->version+1,'updated_user_id'=>$actorUserId]);
            } while (Staff::withTrashed()->where('code',$number)->exists() || HrEmployeeNumberAlias::query()->where('employee_number',$number)->exists());
            $staff->update(['code'=>$number,'updated_user_id'=>$actorUserId]);
            return $this->reserveAlias($staff, $number, 'canonical', $actorUserId, 'Server-side sequence allocation.');
        });
    }

    private function reserveAlias(Staff $staff, string $number, string $type, string $actorUserId, ?string $reason): string
    {
        $existing=HrEmployeeNumberAlias::query()->where('employee_number',$number)->first();
        abort_if($existing && $existing->staff_id!==$staff->id,409,'Employee number alias belongs to another canonical employee.');
        if(!$existing) HrEmployeeNumberAlias::create(['employee_number'=>$number,'staff_id'=>$staff->id,'company_id'=>$staff->company_id,'alias_type'=>$type,'is_canonical'=>true,'effective_from'=>now()->toDateString(),'reason'=>$reason,'approved_by'=>$actorUserId]);
        return $number;
    }
}
