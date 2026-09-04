<?php

namespace App\Console\Commands;

use App\Services\Hr\Leave\LeaveWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessLeaveAccruals extends Command
{
    protected $signature='hr:process-leave-accruals {--as-of=} {--company=} {--commit : Persist idempotent ledger entries}';
    protected $description='Preview or post policy-driven leave accrual, leave-year expiry, and carry-forward entries.';

    public function handle(LeaveWorkflowService$service):int
    {
        $asOf=CarbonImmutable::parse($this->option('as-of')?:now()->toDateString())->startOfDay();$actor=(string)config('hr.system_user_id');if($this->option('commit')){if(!config('hr.features.leave_overtime',false)){$this->error('HR leave/overtime feature is disabled.');return self::FAILURE;}if(!$actor||!DB::table('users')->where('id',$actor)->exists()){$this->error('HR_SYSTEM_USER_ID must identify an existing system actor before committed processing.');return self::FAILURE;}}
        $rows=DB::table('hr_leave_policy_assignments as assignment')->join('hr_leave_policies as policy','policy.id','=','assignment.policy_id')->join('hr_leave_balance_accounts as account',fn($join)=>$join->on('account.staff_id','=','assignment.staff_id')->on('account.leave_type_id','=','policy.leave_type_id'))->whereNotNull('assignment.approved_at')->where('policy.status','approved')->whereDate('assignment.effective_from','<=',$asOf)->where(fn($q)=>$q->whereNull('assignment.effective_until')->orWhereDate('assignment.effective_until','>',$asOf))->whereDate('policy.effective_from','<=',$asOf)->where(fn($q)=>$q->whereNull('policy.effective_until')->orWhereDate('policy.effective_until','>',$asOf))->when($this->option('company'),fn($q,$id)=>$q->where('assignment.company_id',$id))->select(['assignment.id as assignment_id','account.id as account_id','policy.id as policy_id','policy.version','policy.rules'])->get();$preview=[];
        foreach($rows as$row){$rules=json_decode($row->rules,true,512,JSON_THROW_ON_ERROR);$cadence=$rules['accrual_cadence']??null;$due=$cadence==='monthly'&&$asOf->day===(int)($rules['accrual_day']??1)||$cadence==='annual'&&$asOf->format('m-d')===($rules['accrual_month_day']??'01-01');if($due&&($minutes=(int)($rules['accrual_minutes']??0))>0){$source='accrual|'.$row->assignment_id.'|'.($cadence==='monthly'?$asOf->format('Y-m'):$asOf->format('Y'));$expires=isset($rules['accrual_expiry_months'])?$asOf->addMonths((int)$rules['accrual_expiry_months'])->toDateString():null;$preview[]=['account'=>$row->account_id,'type'=>'accrual','minutes'=>$minutes,'source'=>$source];if($this->option('commit'))$service->postAutomatedBalance($row->account_id,'accrual',$minutes,$asOf->toDateString(),$expires,'policy_accrual',$source,'Policy accrual',['policy_id'=>$row->policy_id,'version'=>$row->version,'rules'=>$rules],$actor);}
            if($asOf->format('m-d')===($rules['leave_year_start']??'01-01')){$prior=$service->balance($row->account_id,$asOf->subDay()->toDateString());$carry=max(0,min($prior,(int)($rules['carry_forward_limit_minutes']??0)));$yearSource='leave-year|'.$row->assignment_id.'|'.$asOf->format('Y');if($prior>0){$preview[]=['account'=>$row->account_id,'type'=>'expiry','minutes'=>-$prior,'source'=>$yearSource];if($this->option('commit'))$service->postAutomatedBalance($row->account_id,'expiry',-$prior,$asOf->toDateString(),null,'policy_year_close',$yearSource,'Close prior leave year',['prior_balance'=>$prior,'rules'=>$rules],$actor);}if($carry>0){$preview[]=['account'=>$row->account_id,'type'=>'carry_forward','minutes'=>$carry,'source'=>$yearSource];if($this->option('commit'))$service->postAutomatedBalance($row->account_id,'carry_forward',$carry,$asOf->toDateString(),isset($rules['carry_forward_expiry_months'])?$asOf->addMonths((int)$rules['carry_forward_expiry_months'])->toDateString():null,'policy_year_close',$yearSource,'Carry forward within policy cap',['prior_balance'=>$prior,'carry'=>$carry,'rules'=>$rules],$actor);}}
        }
        $this->info(($this->option('commit')?'Posted/replayed ':'Previewed ').count($preview).' leave ledger operations for '.$asOf->toDateString().'.');return self::SUCCESS;
    }
}
