<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SriLankaAttendanceDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $company=DB::table('companies')->where('is_default',true)->whereNull('deleted_at')->first();
        if(!$company){$this->command?->warn('Sri Lanka attendance defaults skipped: no default company exists.');return;}
        $actor=DB::table('staff')->where('company_id',$company->id)->whereNotNull('user_id')->whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('created_at')->value('user_id');
        if(!$actor){$this->command?->warn('Sri Lanka attendance defaults skipped: the default company has no active Staff user for audit ownership.');return;}

        DB::transaction(function()use($company,$actor){
            $now=now();$from=CarbonImmutable::today('Asia/Colombo')->startOfYear()->toDateString();
            $calendar=DB::table('hr_work_calendars')->where('company_id',$company->id)->where('code','LK-STANDARD')->first();
            if(!$calendar){$id=(string)Str::uuid();DB::table('hr_work_calendars')->insert(['id'=>$id,'company_id'=>$company->id,'code'=>'LK-STANDARD','name'=>'Sri Lanka Standard Office Calendar','timezone'=>'Asia/Colombo','weekly_working_days'=>json_encode(['monday','tuesday','wednesday','thursday','friday'],JSON_THROW_ON_ERROR),'status'=>'active','effective_from'=>$from,'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now]);$calendar=DB::table('hr_work_calendars')->find($id);}
            $shift=DB::table('hr_shift_definitions')->where('company_id',$company->id)->where('code','LK-OFFICE')->first();
            if(!$shift){$id=(string)Str::uuid();DB::table('hr_shift_definitions')->insert(['id'=>$id,'company_id'=>$company->id,'code'=>'LK-OFFICE','name'=>'Sri Lanka Standard Office Shift','start_time'=>'08:30','end_time'=>'17:30','ends_next_day'=>false,'unpaid_break_minutes'=>60,'grace_in_minutes'=>10,'grace_out_minutes'=>10,'minimum_half_day_minutes'=>240,'minimum_full_day_minutes'=>480,'timezone'=>'Asia/Colombo','status'=>'active','effective_from'=>$from,'created_by'=>$actor,'created_at'=>$now,'updated_at'=>$now]);$shift=DB::table('hr_shift_definitions')->find($id);}
            $policy=DB::table('hr_attendance_policies')->where('company_id',$company->id)->where('code','LK-STANDARD')->first();
            if(!$policy){$id=(string)Str::uuid();DB::table('hr_attendance_policies')->insert(['id'=>$id,'company_id'=>$company->id,'code'=>'LK-STANDARD','name'=>'Sri Lanka Standard Attendance Policy','rules'=>json_encode(['maximum_payable_minutes'=>480],JSON_THROW_ON_ERROR),'status'=>'approved','effective_from'=>$from,'created_by'=>$actor,'approved_by'=>$actor,'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$policy=DB::table('hr_attendance_policies')->find($id);}
            $created=0;$skipped=0;
            foreach(DB::table('staff')->where('company_id',$company->id)->whereNull('employment_ended_at')->whereNull('deleted_at')->pluck('id') as $staffId){
                $overlap=DB::table('hr_roster_assignments')->where('company_id',$company->id)->where('staff_id',$staffId)->whereDate('effective_from','<=',today())->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>',today()))->exists();
                if($overlap){$skipped++;continue;}
                DB::table('hr_roster_assignments')->insert(['id'=>(string)Str::uuid(),'company_id'=>$company->id,'staff_id'=>$staffId,'calendar_id'=>$calendar->id,'shift_id'=>$shift->id,'policy_id'=>$policy->id,'effective_from'=>$from,'reason'=>'Default Sri Lanka office attendance starter configuration. Review against company employment terms and maintain public holidays as calendar overrides.','created_by'=>$actor,'approved_by'=>$actor,'approved_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);$created++;
            }
            $this->command?->info("Sri Lanka attendance defaults ready for {$company->name}: {$created} rosters created, {$skipped} retained.");
        });
    }
}
