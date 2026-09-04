<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployeeTimelineEvent;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\HrRehireCase;
use App\Models\Hr\HrStaffProfileVersion;
use App\Models\Hr\HrEmployeeRecord;
use App\Models\Staff;
use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Support\Facades\DB;

class PeopleCoreService
{
    public function __construct(
        private readonly EmployeeNumberAllocator $numbers,
        private readonly UserContextService $contexts,
        private readonly ReportingLineAdministrationService $reportingLines,
    ) {}

    public function initializeStaff(Staff $staff, array $input, string $actorUserId): Staff
    {
        if (! config('hr.features.people_core', false)) return $staff;
        if (! $staff->company_id) return $staff;
        return DB::transaction(function () use ($staff, $input, $actorUserId) {
            abort_unless(DB::table('companies')->where('id',$staff->company_id)->lockForUpdate()->first(),404,'Staff legal entity was not found.');
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->numbers->allocate($staff, $actorUserId, $input['code'] ?? null, $input['employee_number_override_reason'] ?? null);
            $spell = HrEmploymentSpell::query()->where('staff_id', $staff->id)->where('status', 'active')->lockForUpdate()->first();
            if (! $spell) {
                $joined = $input['joined_at'] ?? now()->toDateString();
                $spell = HrEmploymentSpell::create([
                    'staff_id'=>$staff->id,'company_id'=>$staff->company_id,'employment_type_id'=>$input['employment_type_id']??null,
                    'spell_number'=>(int) HrEmploymentSpell::query()->where('staff_id',$staff->id)->max('spell_number')+1,
                    'joined_at'=>$joined,'service_date'=>$input['service_date']??$joined,'confirmation_date'=>$input['confirmation_date']??null,
                    'status'=>'active','gratuity_service_start'=>$input['service_date']??$joined,
                    'prior_service_decisions'=>['initial_hire'=>true],'created_user_id'=>$actorUserId,
                ]);
                $this->timeline($staff, $spell, 'employment', 'employment_started', 'Employment started', ['employee_number'=>$staff->code], "employment-started:{$spell->id}", $joined);
            }
            if (! empty($input['position_id']) || ! empty($input['organization_unit_id'])) $this->createAssignment($staff, $spell, $input, $actorUserId, 'initial_hire');
            return $staff->refresh();
        });
    }

    public function closeEmployment(Staff $staff, string $reason, string $actorUserId): ?HrEmploymentSpell
    {
        if (! config('hr.features.people_core', false)) return null;
        return DB::transaction(function()use($staff,$reason,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$staff->company_id)->lockForUpdate()->first(),404,'Staff legal entity was not found.');
            $staff=Staff::withTrashed()->lockForUpdate()->findOrFail($staff->id);
            $spell=HrEmploymentSpell::query()->where('staff_id',$staff->id)->where('status','active')->lockForUpdate()->first();
            if(!$spell)return null;
            $date=now()->toDateString();
            $spell->update(['last_working_date'=>$date,'terminated_at'=>$date,'termination_reason'=>$reason,'status'=>'terminated']);
            HrEmploymentAssignment::query()->where('employment_spell_id',$spell->id)->whereNull('effective_until')->update(['effective_until'=>$date]);
            $this->reportingLines->closeForEmploymentEnd($staff->id,$staff->company_id,$date,$actorUserId,'Employment ended: '.$reason);
            $this->timeline($staff,$spell,'employment','employment_terminated','Employment terminated',['reason'=>$reason],"employment-terminated:{$spell->id}",now());
            return$spell->refresh();
        });
    }

    public function prepareRehire(Staff $staff, array $data, string $actorUserId): HrRehireCase
    {
        abort_unless(config('hr.features.people_core', false), 409, 'HR People Core writes are not enabled.');
        abort_unless($staff->trashed() || $staff->employment_ended_at, 422, 'Only a former employee can enter rehire review.');
        $prior = HrEmploymentSpell::query()->where('staff_id',$staff->id)->where('status','terminated')->latest('spell_number')->firstOrFail();
        $checksum=hash('sha256',json_encode(['staff_id'=>$staff->id,'prior_spell_id'=>$prior->id,'data'=>$data],JSON_UNESCAPED_SLASHES));
        if($existing=HrRehireCase::query()->where('idempotency_key',$data['idempotency_key'])->first()){
            abort_unless(hash_equals($existing->request_payload_checksum,$checksum),409,'This rehire key was already used with different facts.');
            return $existing;
        }
        return HrRehireCase::create([
            'staff_id'=>$staff->id,'company_id'=>$staff->company_id,'prior_spell_id'=>$prior->id,'status'=>'pending_approval',
            'proposed_rehire_date'=>$data['proposed_rehire_date'],
            'duplicate_match_snapshot'=>['staff_id'=>$staff->id,'user_id'=>$staff->user_id,'employee_number'=>$staff->code,'nic'=>$staff->nic],
            'eligibility_snapshot'=>$data['eligibility_snapshot'],'prior_service_decisions'=>$data['prior_service_decisions'],
            'access_reactivation_plan'=>$data['access_reactivation_plan']??null,'benefit_statutory_review'=>$data['benefit_statutory_review']??null,
            'prepared_by'=>$actorUserId,'idempotency_key'=>$data['idempotency_key'],'request_payload_checksum'=>$checksum,
        ]);
    }

    public function approveRehire(HrRehireCase $case, array $assignment, string $actorUserId): HrRehireCase
    {
        abort_unless(config('hr.features.people_core', false), 409, 'HR People Core writes are not enabled.');
        return DB::transaction(function () use ($case,$assignment,$actorUserId) {
            abort_unless(DB::table('companies')->where('id',$case->company_id)->lockForUpdate()->first(),404,'Staff legal entity was not found.');
            $case=HrRehireCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($case->status==='approved') return $case;
            abort_unless($case->status==='pending_approval',422,'Rehire case is not pending approval.');
            abort_if($case->prepared_by===$actorUserId,403,'Rehire preparer and approver must be different users.');
            $staff=Staff::withTrashed()->lockForUpdate()->findOrFail($case->staff_id);
            abort_if(HrEmploymentSpell::query()->where('staff_id',$staff->id)->where('status','active')->exists(),409,'Employee already has an active employment spell.');
            $staff->restore(); $staff->update(['employment_ended_at'=>null,'termination_reason'=>null,'terminated_by'=>null,'updated_user_id'=>$actorUserId]);
            $rehireDate=$case->proposed_rehire_date->toDateString();
            $spell=HrEmploymentSpell::create(['staff_id'=>$staff->id,'company_id'=>$case->company_id,'employment_type_id'=>$assignment['employment_type_id']??null,'spell_number'=>(int)HrEmploymentSpell::query()->where('staff_id',$staff->id)->max('spell_number')+1,'joined_at'=>$rehireDate,'service_date'=>$rehireDate,'rehire_date'=>$rehireDate,'status'=>'active','gratuity_service_start'=>$rehireDate,'gratuity_service_decision'=>$case->prior_service_decisions['gratuity']??'New gratuity-service clock required.','prior_service_decisions'=>$case->prior_service_decisions,'created_user_id'=>$actorUserId]);
            if (!empty($assignment['position_id'])||!empty($assignment['organization_unit_id'])) $this->createAssignment($staff,$spell,$assignment,$actorUserId,'rehire');
            $context=$staff->user->contexts()->where('context_type','staff')->first();
            if ($context) {
                $context->update(['is_active'=>true,'updated_user_id'=>$actorUserId]);
                $context->roles()->get()->each(fn ($role) => $this->contexts->assignRolesToContext($staff->user,$context,[$role->id]));
            }
            $case->update(['status'=>'approved','approved_by'=>$actorUserId,'approved_at'=>now(),'new_spell_id'=>$spell->id]);
            $this->timeline($staff,$spell,'employment','employee_rehired','Employee rehired',['retained_employee_number'=>$staff->code,'prior_spell_id'=>$case->prior_spell_id,'prior_service_decisions'=>$case->prior_service_decisions],"rehire-approved:{$case->id}",now());
            return $case->refresh();
        });
    }

    public function addProfileVersion(Staff $staff,array $profile,string $reason,string $actorUserId): HrStaffProfileVersion
    {
        abort_unless(config('hr.features.people_core',false),409,'HR People Core writes are not enabled.');
        return DB::transaction(function()use($staff,$profile,$reason,$actorUserId){
            $staff=Staff::withTrashed()->lockForUpdate()->findOrFail($staff->id);
            $version=(int)HrStaffProfileVersion::query()->where('staff_id',$staff->id)->max('version')+1;
            $checksum=hash('sha256',json_encode($profile,JSON_UNESCAPED_SLASHES));
            $existing=HrStaffProfileVersion::query()->where('staff_id',$staff->id)->where('profile_checksum',$checksum)->latest('version')->first();
            if($existing)return $existing;
            $row=HrStaffProfileVersion::create(['staff_id'=>$staff->id,'version'=>$version,'encrypted_profile'=>$profile,'profile_checksum'=>$checksum,'change_reason'=>$reason,'changed_by'=>$actorUserId,'effective_at'=>now()]);
            $spell=HrEmploymentSpell::query()->where('staff_id',$staff->id)->latest('spell_number')->first();
            if($spell)$this->timeline($staff,$spell,'people','profile_versioned','Employee profile updated',['version'=>$version,'fields'=>array_keys($profile)],"profile-version:{$row->id}",now());
            return $row;
        });
    }

    public function addEmployeeRecord(Staff $staff,array $data,string $actorUserId): HrEmployeeRecord
    {
        abort_unless(config('hr.features.people_core',false),409,'HR People Core writes are not enabled.');
        return DB::transaction(function()use($staff,$data,$actorUserId){
            $row=HrEmployeeRecord::create($data+['staff_id'=>$staff->id]);
            $spell=$data['employment_spell_id']??HrEmploymentSpell::query()->where('staff_id',$staff->id)->latest('spell_number')->value('id');
            if($spell){$spellModel=HrEmploymentSpell::query()->find($spell);if($spellModel)$this->timeline($staff,$spellModel,'people','employee_record_added',$row->title,['record_type'=>$row->record_type,'verification_status'=>$row->verification_status],"employee-record:{$row->id}",$row->effective_date??now());}
            return $row;
        });
    }

    public function applyApprovedAssignmentChange(Staff $staff,array $data,string $actorUserId,string $changeRequestId):HrEmploymentAssignment
    {
        abort_unless(config('hr.features.people_core',false),409,'HR People Core writes are not enabled.');
        return DB::transaction(function()use($staff,$data,$actorUserId,$changeRequestId){abort_unless(DB::table('companies')->where('id',$staff->company_id)->lockForUpdate()->first(),404,'Staff legal entity was not found.');$staff=Staff::query()->lockForUpdate()->findOrFail($staff->id);$spell=HrEmploymentSpell::query()->where('staff_id',$staff->id)->where('status','active')->lockForUpdate()->firstOrFail();abort_if(HrEmploymentAssignment::query()->where('staff_id',$staff->id)->where('effective_from','>',$data['effective_from'])->exists(),409,'A later assignment already exists; review the effective-date sequence.');$assignment=$this->createAssignment($staff,$spell,$data,$actorUserId,'approved_'.$data['change_type']);$this->timeline($staff,$spell,'lifecycle','assignment_changed',ucwords(str_replace('_',' ',$data['change_type'])),['assignment_id'=>$assignment->id,'effective_from'=>$assignment->effective_from->toDateString(),'change_request_id'=>$changeRequestId],"assignment-change:{$changeRequestId}",$assignment->effective_from);return$assignment;});
    }

    private function createAssignment(Staff $staff,HrEmploymentSpell $spell,array $data,string $actor,string $reason): HrEmploymentAssignment
    {
        $start=$data['effective_from']??$spell->joined_at->toDateString();
        if(!empty($data['payroll_group_code']))$this->assertPayrollGroupCode($staff->company_id,$data['payroll_group_code'],$start);
        HrEmploymentAssignment::query()->where('staff_id',$staff->id)->whereNull('effective_until')->update(['effective_until'=>$start]);
        $assignment=HrEmploymentAssignment::create(['employment_spell_id'=>$spell->id,'staff_id'=>$staff->id,'company_id'=>$staff->company_id,'position_id'=>$data['position_id']??null,'organization_unit_id'=>$data['organization_unit_id']??null,'manager_staff_id'=>$data['manager_staff_id']??null,'dotted_line_manager_staff_id'=>$data['dotted_line_manager_staff_id']??null,'hr_partner_staff_id'=>$data['hr_partner_staff_id']??null,'cost_centre_code'=>$data['cost_centre_code']??null,'location_code'=>$data['location_code']??null,'payroll_group_code'=>$data['payroll_group_code']??null,'default_shift_code'=>$data['default_shift_code']??null,'work_pattern_code'=>$data['work_pattern_code']??null,'assignment_type'=>'primary','effective_from'=>$start,'change_reason'=>$reason,'snapshot'=>$data,'approved_by'=>$actor]);
        $this->reportingLines->projectAssignmentManagers($assignment,$actor);
        return$assignment;
    }

    /**
     * Forward-only referential enforcement: only a newly written assignment's
     * payroll_group_code must resolve to a governed, effective hr_payroll_groups
     * row. Pre-existing free-text values on prior assignment rows are left
     * untouched — retroactively validating them needs a reviewed disposition
     * decision for any non-matching legacy code, which is explicitly out of
     * scope for this slice (see §24.16).
     */
    private function assertPayrollGroupCode(string $companyId,string $code,string $effectiveAt): void
    {
        $exists=DB::table('hr_payroll_groups')->where('company_id',$companyId)->where('code',$code)->where('status','active')
            ->where('effective_from','<=',$effectiveAt)
            ->where(fn($range)=>$range->whereNull('effective_until')->orWhere('effective_until','>=',$effectiveAt))
            ->exists();
        abort_unless($exists,422,'Payroll group code must reference an active, effective governed payroll group.');
    }

    private function timeline(Staff $staff,HrEmploymentSpell $spell,string $domain,string $type,string $title,array $summary,string $key,$at): void
    {
        HrEmployeeTimelineEvent::firstOrCreate(['idempotency_key'=>$key],['staff_id'=>$staff->id,'employment_spell_id'=>$spell->id,'domain'=>$domain,'event_type'=>$type,'source_type'=>'employment_spell','source_id'=>$spell->id,'title'=>$title,'safe_summary'=>$summary,'confidentiality'=>'hr_private','effective_at'=>$at,'recorded_at'=>now()]);
    }
}
