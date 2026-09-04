<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmploymentSpell;
use App\Models\Hr\HrPeopleExport;
use App\Models\Hr\HrPeopleImportJob;
use App\Models\Hr\HrPeopleDuplicateReview;
use App\Models\Hr\HrPeopleIdentityLink;
use App\Models\Staff;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PeopleCoreMigrationService
{
    public const MAPPING_VERSION = 'people-core-employment-v1';
    private const COLUMNS = ['employee_number','joined_at','service_date','confirmation_date','employment_type_code','organization_unit_code','position_number','manager_employee_number','location_code','cost_centre_code','payroll_group_code','default_shift_code','work_pattern_code'];

    public function __construct(private readonly PeopleCoreService $people) {}

    public function reconciliation(string $companyId): array
    {
        $staff = Staff::withTrashed()->where('company_id', $companyId);
        $active = (clone $staff)->whereNull('deleted_at')->whereNull('employment_ended_at');
        $former = (clone $staff)->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereNotNull('employment_ended_at'));
        $activeMissingIdentity = (clone $active)->whereNull('user_id')->count();
        $activeMissingCode = (clone $active)->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))->count();
        $activeMissingSpell = (clone $active)->whereDoesntHave('employmentSpells', fn ($q) => $q->where('status', 'active'))->count();
        $activeMultipleSpells = DB::table('hr_employment_spells')->where('company_id', $companyId)->where('status', 'active')
            ->groupBy('staff_id')->havingRaw('count(*) > 1')->get()->count();
        $activeMissingAssignment = (clone $active)->whereDoesntHave('employmentAssignments', fn ($q) => $q->where('effective_from', '<=', now())->where(fn ($range) => $range->whereNull('effective_until')->orWhere('effective_until', '>', now())))->count();
        $formerOpenSpell = (clone $former)->whereHas('employmentSpells', fn ($q) => $q->where('status', 'active'))->count();
        $canonicalSelectionsPendingConsolidation = HrPeopleDuplicateReview::query()->where('company_id', $companyId)->where('disposition', 'canonical_selected')->whereNull('consolidated_at')->count();
        $issues = compact('activeMissingIdentity', 'activeMissingCode', 'activeMissingSpell', 'activeMultipleSpells', 'activeMissingAssignment', 'formerOpenSpell', 'canonicalSelectionsPendingConsolidation');

        return ['company_id'=>$companyId, 'generated_at'=>now()->toIso8601String(), 'counts'=>['staff'=>(clone $staff)->count(),'active'=>(clone $active)->count(),'former'=>(clone $former)->count()], 'issues'=>$issues, 'reconciled'=>array_sum($issues)===0];
    }

    public function preview(UploadedFile $file, string $companyId, string $actorId, string $key): HrPeopleImportJob
    {
        $content = file_get_contents($file->getRealPath());
        abort_if($content === false || strlen($content) > 5_000_000, 422, 'The People Core CSV must be readable and no larger than 5 MB.');
        $checksum = hash('sha256', $content);
        $requestChecksum = hash('sha256', CanonicalJson::encode(['company_id'=>$companyId,'file_checksum'=>$checksum,'mapping_version'=>self::MAPPING_VERSION]));
        $existing = HrPeopleImportJob::query()->where('company_id',$companyId)->where('idempotency_key',$key)->first();
        if ($existing) { abort_unless(hash_equals($existing->request_checksum,$requestChecksum),409,'The import key was reused with a different file.'); return $existing; }
        $handle = fopen($file->getRealPath(), 'rb'); abort_unless($handle,422,'The People Core CSV could not be opened.');
        $header = fgetcsv($handle); $header=$header?array_map(fn($v)=>mb_strtolower(trim((string)$v)),$header):[];
        abort_unless(array_slice($header,0,4)===array_slice(self::COLUMNS,0,4)&&array_diff($header,self::COLUMNS)===[],422,'CSV must start with employee_number,joined_at,service_date,confirmation_date and may use only supported assignment columns.');
        $rows=[]; $number=1;
        while (($raw=fgetcsv($handle))!==false) {
            $number++; abort_if($number>10001,422,'A People Core import is limited to 10,000 data rows.');
            $payload=array_fill_keys(self::COLUMNS,'');foreach($header as $index=>$column)$payload[$column]=trim((string)($raw[$index]??''));
            $payload=array_map(fn($v)=>trim((string)$v),$payload); $errors=[];
            if($payload['employee_number']==='')$errors[]='Employee number is required.';
            foreach(['joined_at','service_date'] as $field)if(!$this->date($payload[$field]))$errors[]="{$field} must be YYYY-MM-DD.";
            if($payload['confirmation_date']!==''&&!$this->date($payload['confirmation_date']))$errors[]='confirmation_date must be blank or YYYY-MM-DD.';
            $matches=$payload['employee_number']===''?collect():Staff::withTrashed()->where('company_id',$companyId)->where('code',$payload['employee_number'])->get();
            if($matches->count()!==1)$errors[]=$matches->isEmpty()?'No Staff matches this employee number.':'Employee number is ambiguous in this legal entity.';
            $staff=$matches->first();
            if($staff&&HrEmploymentSpell::query()->where('staff_id',$staff->id)->exists())$errors[]='Employment history already exists; use the governed lifecycle workflow.';
            $this->resolveAssignmentReferences($payload,$companyId,$staff?->id,$errors);
            $rows[]=['row_number'=>$number,'payload'=>$payload,'errors'=>$errors,'staff_id'=>$staff?->id];
        }
        fclose($handle); abort_if($rows===[],422,'The People Core CSV has no data rows.');
        $path='people-core-imports/'.$companyId.'/'.Str::uuid().'.csv'; abort_unless(Storage::disk('hr_private')->put($path,$content),500,'The source import file could not be retained.');
        return DB::transaction(function()use($rows,$file,$companyId,$actorId,$key,$path,$checksum,$requestChecksum){
            $accepted=collect($rows)->where('errors',[])->count();
            $job=HrPeopleImportJob::create(['company_id'=>$companyId,'mode'=>'preview','status'=>$accepted?'ready':'rejected','original_file_name'=>$file->getClientOriginalName(),'disk'=>'hr_private','path'=>$path,'file_checksum'=>$checksum,'mapping_version'=>self::MAPPING_VERSION,'row_count'=>count($rows),'accepted_count'=>$accepted,'rejected_count'=>count($rows)-$accepted,'reconciliation_totals'=>['before'=>$this->reconciliation($companyId)],'request_checksum'=>$requestChecksum,'idempotency_key'=>$key,'created_by'=>$actorId]);
            foreach($rows as $row)DB::table('hr_people_import_rows')->insert(['id'=>(string)Str::uuid(),'import_job_id'=>$job->id,'row_number'=>$row['row_number'],'source_row_key'=>$row['payload']['employee_number']?:'row-'.$row['row_number'],'normalized_payload'=>json_encode($row['payload'],JSON_THROW_ON_ERROR),'payload_checksum'=>hash('sha256',CanonicalJson::encode($row['payload'])),'outcome'=>$row['errors']?'rejected':'accepted','errors'=>$row['errors']?json_encode($row['errors'],JSON_THROW_ON_ERROR):null,'matched_staff_id'=>$row['staff_id'],'created_user_id'=>$actorId,'created_at'=>now(),'updated_at'=>now()]);
            return $job;
        });
    }

    public function commit(HrPeopleImportJob $job, string $companyId, string $actorId): HrPeopleImportJob
    {
        return DB::transaction(function()use($job,$companyId,$actorId){
            $job=HrPeopleImportJob::query()->whereKey($job->id)->where('company_id',$companyId)->lockForUpdate()->firstOrFail();
            if($job->status==='committed')return $job;
            abort_unless($job->status==='ready'&&$job->rejected_count===0,409,'Every import row must pass preview before commit.');
            $rows=DB::table('hr_people_import_rows')->where('import_job_id',$job->id)->orderBy('row_number')->lockForUpdate()->get();
            foreach($rows as $row){$payload=json_decode($row->normalized_payload,true,512,JSON_THROW_ON_ERROR);$staff=Staff::withTrashed()->whereKey($row->matched_staff_id)->where('company_id',$companyId)->lockForUpdate()->firstOrFail();abort_if(HrEmploymentSpell::query()->where('staff_id',$staff->id)->lockForUpdate()->exists(),409,'Employment history changed after preview; create a new preview.');$spell=HrEmploymentSpell::create(['staff_id'=>$staff->id,'company_id'=>$companyId,'employment_type_id'=>$payload['employment_type_id']?:null,'spell_number'=>1,'joined_at'=>$payload['joined_at'],'service_date'=>$payload['service_date'],'confirmation_date'=>$payload['confirmation_date']?:null,'status'=>($staff->trashed()||$staff->employment_ended_at)?'terminated':'active','last_working_date'=>$staff->employment_ended_at?->toDateString(),'terminated_at'=>$staff->employment_ended_at?->toDateString(),'termination_reason'=>$staff->termination_reason,'gratuity_service_start'=>$payload['service_date'],'prior_service_decisions'=>['legacy_import'=>true,'mapping_version'=>self::MAPPING_VERSION],'created_user_id'=>$actorId]);if(!$staff->trashed()&&!$staff->employment_ended_at&&($payload['position_id']||$payload['organization_unit_id'])){$this->people->addImportedInitialAssignment($staff,$spell,['effective_from'=>$payload['joined_at'],'position_id'=>$payload['position_id']?:null,'organization_unit_id'=>$payload['organization_unit_id']?:null,'manager_staff_id'=>$payload['manager_staff_id']?:null,'location_code'=>$payload['location_code']?:null,'cost_centre_code'=>$payload['cost_centre_code']?:null,'payroll_group_code'=>$payload['payroll_group_code']?:null,'default_shift_code'=>$payload['default_shift_code']?:null,'work_pattern_code'=>$payload['work_pattern_code']?:null],$actorId);}DB::table('hr_people_import_rows')->where('id',$row->id)->update(['outcome'=>'committed','created_spell_id'=>$spell->id,'updated_user_id'=>$actorId,'updated_at'=>now()]);}
            $after=$this->reconciliation($companyId);$job->update(['mode'=>'committed','status'=>'committed','committed_by'=>$actorId,'committed_at'=>now(),'reconciliation_totals'=>['before'=>$job->reconciliation_totals['before']??null,'after'=>$after]]);return $job->fresh();
        },3);
    }

    public function export(string $companyId, string $actorId, string $key): HrPeopleExport
    {
        $existing=HrPeopleExport::query()->where('company_id',$companyId)->where('generated_by',$actorId)->where('idempotency_key',$key)->first();
        if($existing)return $existing;
        $rows=Staff::withTrashed()->with(['user:id,first_name,last_name,email','employmentSpells'=>fn($q)=>$q->orderBy('spell_number')])->where('company_id',$companyId)->orderBy('code')->orderBy('id')->get();
        $handle=fopen('php://temp','w+b');
        fputcsv($handle,['employee_number','name','email','staff_category','employment_status','spell_number','joined_at','service_date','confirmation_date','last_working_date','terminated_at']);
        $count=0;
        foreach($rows as $staff){$spells=$staff->employmentSpells->isEmpty()?collect([null]):$staff->employmentSpells;foreach($spells as $spell){fputcsv($handle,array_map(fn($value)=>$this->csvCell($value),[$staff->code,trim(($staff->user?->first_name??'').' '.($staff->user?->last_name??'')),$staff->user?->email,$staff->staff_type,$spell?->status??'missing',$spell?->spell_number,$spell?->joined_at?->toDateString(),$spell?->service_date?->toDateString(),$spell?->confirmation_date?->toDateString(),$spell?->last_working_date?->toDateString(),$spell?->terminated_at?->toDateString()]));$count++;}}
        rewind($handle);$content=stream_get_contents($handle);fclose($handle);
        $scopeChecksum=hash('sha256',CanonicalJson::encode(['company_id'=>$companyId,'staff_ids'=>$rows->pluck('id')->sort()->values()->all()]));
        $fileName='people-core-'.now()->format('Ymd-His').'-'.Str::random(8).'.csv';$path='people-core-exports/'.$companyId.'/'.$fileName;
        abort_unless(Storage::disk('hr_private')->put($path,$content),500,'The private People Core export could not be written.');
        return HrPeopleExport::create(['company_id'=>$companyId,'disk'=>'hr_private','path'=>$path,'file_name'=>$fileName,'file_checksum'=>hash('sha256',$content),'file_size'=>strlen($content),'row_count'=>$count,'scope_checksum'=>$scopeChecksum,'idempotency_key'=>$key,'generated_by'=>$actorId,'generated_at'=>now(),'expires_at'=>now()->addDays((int)config('hr.report_artifact_retention_days',30))]);
    }

    public function detectDuplicates(string $companyId,string $actorId): array
    {
        $staff=Staff::withTrashed()->with('user:id,first_name,last_name,email')->where('company_id',$companyId)->get();$created=0;
        foreach(['user_id','code','nic_fingerprint','license_no_fingerprint'] as $field){$groups=$staff->filter(fn($row)=>filled($row->{$field}))->groupBy(fn($row)=>mb_strtolower(trim((string)$row->{$field})))->filter(fn($rows)=>$rows->count()>1);foreach($groups as $value=>$rows){$ids=$rows->pluck('id')->map(fn($id)=>(string)$id)->sort()->values()->all();$fingerprint=hash('sha256',$field.'|'.$value);$review=HrPeopleDuplicateReview::withTrashed()->where('company_id',$companyId)->where('match_kind',$field)->where('match_fingerprint',$fingerprint)->first();if(!$review){HrPeopleDuplicateReview::create(['company_id'=>$companyId,'match_kind'=>$field,'match_fingerprint'=>$fingerprint,'candidate_staff_ids'=>$ids,'safe_candidate_snapshot'=>$rows->map(fn($row)=>['staff_id'=>$row->id,'employee_number'=>$row->code,'name'=>trim(($row->user?->first_name??'').' '.($row->user?->last_name??'')),'employment_ended_at'=>$row->employment_ended_at?->toIso8601String(),'deleted_at'=>$row->deleted_at?->toIso8601String()])->values()->all(),'status'=>'pending_review','prepared_by'=>$actorId]);$created++;}elseif($review->status==='pending_review'&&$review->candidate_staff_ids!==$ids){$review->update(['candidate_staff_ids'=>$ids,'safe_candidate_snapshot'=>$rows->map(fn($row)=>['staff_id'=>$row->id,'employee_number'=>$row->code,'name'=>trim(($row->user?->first_name??'').' '.($row->user?->last_name??'')),'employment_ended_at'=>$row->employment_ended_at?->toIso8601String(),'deleted_at'=>$row->deleted_at?->toIso8601String()])->values()->all(),'version'=>$review->version+1]);}}}
        return ['created'=>$created,'pending'=>HrPeopleDuplicateReview::where('company_id',$companyId)->where('status','pending_review')->count()];
    }

    public function duplicateReviews(string $companyId,int $perPage=25): mixed
    {
        return HrPeopleDuplicateReview::where('company_id',$companyId)->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 ELSE 1 END")->orderByDesc('created_at')->paginate(min(max($perPage,1),100));
    }

    public function decideDuplicate(HrPeopleDuplicateReview $review,string $companyId,array $data,string $actorId): HrPeopleDuplicateReview
    {
        return DB::transaction(function()use($review,$companyId,$data,$actorId){$review=HrPeopleDuplicateReview::whereKey($review->id)->where('company_id',$companyId)->lockForUpdate()->firstOrFail();abort_unless($review->status==='pending_review',409,'This duplicate review has already been decided.');abort_unless($review->version===(int)$data['expected_version'],409,'The duplicate review changed; refresh before deciding.');abort_if($review->prepared_by===$actorId,403,'The duplicate-review preparer cannot decide the same case.');$canonical=$data['canonical_staff_id']??null;if($data['disposition']==='canonical_selected'){abort_unless($canonical&&in_array($canonical,$review->candidate_staff_ids,true),422,'Canonical Staff must be one of the reviewed candidates.');abort_unless(Staff::withTrashed()->whereKey($canonical)->where('company_id',$companyId)->exists(),422,'Canonical Staff is outside the review legal entity.');}else abort_if($canonical!==null,422,'Canonical Staff is only allowed for canonical-selected decisions.');$review->update(['status'=>'decided','disposition'=>$data['disposition'],'canonical_staff_id'=>$canonical,'reason'=>trim($data['reason']),'decided_by'=>$actorId,'decided_at'=>now(),'version'=>$review->version+1]);return$review->fresh();});
    }

    public function consolidateDuplicate(HrPeopleDuplicateReview $review,string $companyId,int $expectedVersion,string $actorId): HrPeopleDuplicateReview
    {
        return DB::transaction(function()use($review,$companyId,$expectedVersion,$actorId){
            $review=HrPeopleDuplicateReview::query()->whereKey($review->id)->where('company_id',$companyId)->lockForUpdate()->firstOrFail();
            if($review->consolidated_at)return $review;
            abort_unless($review->status==='decided'&&$review->disposition==='canonical_selected'&&$review->canonical_staff_id,409,'Only a canonical-selected duplicate review can be consolidated.');
            abort_unless($review->version===$expectedVersion,409,'The duplicate review changed; refresh before consolidating.');
            $candidateIds=collect($review->candidate_staff_ids)->map(fn($id)=>(string)$id)->unique()->values();
            $staff=Staff::withTrashed()->where('company_id',$companyId)->whereIn('id',$candidateIds)->lockForUpdate()->get()->keyBy(fn($row)=>(string)$row->id);
            abort_unless($staff->count()===$candidateIds->count()&&$staff->has((string)$review->canonical_staff_id),409,'The reviewed Staff candidates changed or left this legal entity.');
            $aliases=$candidateIds->reject(fn($id)=>$id===(string)$review->canonical_staff_id)->values();
            abort_if(HrPeopleIdentityLink::query()->where('company_id',$companyId)->where('alias_staff_id',$review->canonical_staff_id)->where('status','active')->lockForUpdate()->exists(),409,'The selected canonical Staff is already an alias of another identity.');
            abort_if(HrPeopleIdentityLink::query()->where('company_id',$companyId)->whereIn('canonical_staff_id',$aliases)->where('status','active')->lockForUpdate()->exists(),409,'A reviewed alias is already canonical for another identity. Consolidate that case first.');
            foreach($aliases as $aliasId){
                $existing=HrPeopleIdentityLink::query()->where('alias_staff_id',$aliasId)->lockForUpdate()->first();
                abort_if($existing&&($existing->company_id!==$companyId||$existing->canonical_staff_id!==(string)$review->canonical_staff_id||$existing->status!=='active'),409,'A reviewed Staff alias already belongs to another canonical identity.');
                $checksum=hash('sha256',CanonicalJson::encode(['company_id'=>$companyId,'review_id'=>(string)$review->id,'alias_staff_id'=>$aliasId,'canonical_staff_id'=>(string)$review->canonical_staff_id,'reason'=>$review->reason]));
                if(!$existing)HrPeopleIdentityLink::create(['company_id'=>$companyId,'duplicate_review_id'=>$review->id,'alias_staff_id'=>$aliasId,'canonical_staff_id'=>$review->canonical_staff_id,'status'=>'active','reason'=>$review->reason,'evidence_checksum'=>$checksum,'approved_by'=>$actorId,'approved_at'=>now()]);
            }
            $snapshot=['strategy'=>'canonical_link_v1','canonical_staff_id'=>(string)$review->canonical_staff_id,'alias_staff_ids'=>$aliases->all(),'historical_references_rewritten'=>false,'link_count'=>$aliases->count()];
            $review->update(['consolidation_status'=>'linked','consolidation_snapshot'=>$snapshot,'consolidated_by'=>$actorId,'consolidated_at'=>now(),'version'=>$review->version+1]);
            return $review->fresh();
        },3);
    }

    public function download(HrPeopleExport $export, string $companyId, string $actorId): StreamedResponse
    {
        abort_unless($export->company_id===$companyId,404);abort_if($export->expires_at->lte(now()),410,'This People Core export has expired.');
        abort_unless(Storage::disk($export->disk)->exists($export->path),404,'The People Core export file is unavailable.');
        abort_unless(hash_equals($export->file_checksum,hash('sha256',Storage::disk($export->disk)->get($export->path))),409,'The People Core export integrity check failed.');
        $export->increment('download_count');$export->update(['last_downloaded_by'=>$actorId,'last_downloaded_at'=>now()]);
        return Storage::disk($export->disk)->download($export->path,$export->file_name,['Content-Type'=>'text/csv']);
    }

    private function csvCell(mixed $value): string
    {
        $value=(string)($value??'');
        return preg_match('/^[=+\-@]/',$value)===1?"'{$value}":$value;
    }

    private function resolveAssignmentReferences(array &$payload,string $companyId,?string $staffId,array &$errors): void
    {
        $payload['employment_type_id']=$this->resolveCode('hr_employment_types','code',$payload['employment_type_code'],$companyId,$errors,'Employment type',false);
        $payload['organization_unit_id']=$this->resolveCode('hr_organization_units','code',$payload['organization_unit_code'],$companyId,$errors,'Organization unit',true,$payload['joined_at']);
        $payload['position_id']=$this->resolveCode('hr_positions','position_number',$payload['position_number'],$companyId,$errors,'Position',true,$payload['joined_at']);
        $payload['manager_staff_id']=$this->resolveCode('staff','code',$payload['manager_employee_number'],$companyId,$errors,'Manager',false);
        if($payload['manager_staff_id']&&$payload['manager_staff_id']===$staffId)$errors[]='An employee cannot manage their own imported assignment.';
        if($payload['manager_staff_id']&&!DB::table('staff')->where('id',$payload['manager_staff_id'])->whereNull('deleted_at')->whereNull('employment_ended_at')->exists())$errors[]='Manager must be active when the import is committed.';
        if($payload['position_id']){$unit=DB::table('hr_positions')->where('id',$payload['position_id'])->value('organization_unit_id');if($payload['organization_unit_id']&&$unit!==$payload['organization_unit_id'])$errors[]='Position and organization unit do not match.';if(!$payload['organization_unit_id'])$payload['organization_unit_id']=$unit;}
        if($payload['payroll_group_code']!==''&&!$this->resolveCode('hr_payroll_groups','code',$payload['payroll_group_code'],$companyId,$errors,'Payroll group',true,$payload['joined_at']))$payload['payroll_group_code']='';
        $hasAssignmentData=collect(['manager_employee_number','location_code','cost_centre_code','payroll_group_code','default_shift_code','work_pattern_code'])->contains(fn($field)=>$payload[$field]!=='');
        if($hasAssignmentData&&!$payload['organization_unit_id'])$errors[]='An organization unit or position is required when assignment details are supplied.';
    }

    private function resolveCode(string $table,string $column,string $value,string $companyId,array &$errors,string $label,bool $effective,?string $at=null): ?string
    {
        if($value==='')return null;$query=DB::table($table)->where('company_id',$companyId)->where($column,$value);
        if($table!=='staff')$query->where('status','active');
        if($effective&&$at&&$this->date($at))$query->where('effective_from','<=',$at)->where(fn($range)=>$range->whereNull('effective_until')->orWhere('effective_until','>=',$at));
        $rows=$query->get(['id']);if($rows->count()!==1){$errors[]=$rows->isEmpty()?"{$label} code is unavailable in this legal entity.":"{$label} code is ambiguous.";return null;}return(string)$rows->first()->id;
    }

    private function date(string $value): bool { $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value); return $date!==false&&$date->format('Y-m-d')===$value; }
}
