<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\StaffAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RecruitmentController extends Controller
{
    public function requisitions(Request $r): JsonResponse
    {
        $company = $this->company($r, $r->input('company_id'));

        return response()->json(['status' => 'success', 'data' => DB::table('hr_job_requisitions')
            ->where('company_id', $company)->latest()->paginate($r->integer('per_page', 50))]);
    }

    public function positionOptions(Request $r): JsonResponse
    {
        $company = $this->company($r, null);
        $data = $r->validate(['search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid']]);
        $search = trim($data['search'] ?? '');
        $rows = DB::table('hr_positions')->where('company_id', $company)->where('status', '!=', 'inactive')
            ->where('effective_from', '<=', today()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', today()->toDateString()))
            ->when($data['selected_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
                ->where('position_number', 'like', '%'.$search.'%')->orWhere('title', 'like', '%'.$search.'%')))
            ->orderBy('position_number')->limit(50)->get(['id', 'position_number', 'title', 'status']);

        return response()->json(['status' => 'success', 'data' => $rows->map(fn ($row) => [
            'value' => (string) $row->id, 'label' => $row->position_number.' · '.$row->title, 'status' => $row->status,
        ])->values()]);
    }

    public function storeRequisition(Request $r): JsonResponse
    {
        
        $d = $r->validate([
            'company_id' => ['nullable', 'uuid'], 'position_id' => ['required', 'uuid'],
            'organization_unit_id' => ['nullable', 'uuid'], 'code' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'], 'headcount' => ['required', 'integer', 'min:1', 'max:1000'],
            'employment_type_code' => ['nullable', 'string', 'max:80'],
            'requirements' => ['required', 'array', 'min:1'], 'requirements.*' => ['required', 'string', 'max:500'],
            'budget_snapshot' => ['nullable', 'array'], 'target_date' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $company = $this->company($r, $d['company_id'] ?? null);
        $checksum = hash('sha256', json_encode([
            'company_id' => $company, 'position_id' => $d['position_id'],
            'organization_unit_id' => $d['organization_unit_id'] ?? null, 'code' => trim($d['code']),
            'title' => trim($d['title']), 'headcount' => (int) $d['headcount'],
            'employment_type_code' => $d['employment_type_code'] ?? null,
            'requirements' => array_values($d['requirements']), 'budget_snapshot' => $d['budget_snapshot'] ?? null,
            'target_date' => $d['target_date'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($r, $d, $company, $checksum): JsonResponse {
                $existing = DB::table('hr_job_requisitions')->where('company_id', $company)
                    ->where('idempotency_key', $d['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                        'This requisition key was already used with different request facts.');
                    return response()->json(['status' => 'success', 'data' => ['id' => $existing->id, 'status' => $existing->status]]);
                }

                $position = DB::table('hr_positions')->where('id', $d['position_id'])->where('company_id', $company)
                    ->where('status', '!=', 'inactive')->where('effective_from', '<=', today()->toDateString())
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', today()->toDateString()))
                    ->lockForUpdate()->first();
                abort_unless($position, 422, 'Select an active position in the current legal entity.');
                if (! empty($d['organization_unit_id'])) {
                    abort_unless(DB::table('hr_organization_units')->where('id', $d['organization_unit_id'])
                        ->where('company_id', $company)->where('status', 'active')
                        ->where('effective_from', '<=', today()->toDateString())
                        ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', today()->toDateString()))
                        ->lockForUpdate()->exists(), 422, 'Organization unit must be active in the current legal entity.');
                }
                abort_if(DB::table('hr_job_requisitions')->where('code', trim($d['code']))->exists(), 409,
                    'A requisition already uses this code.');

                $id = (string) Str::uuid();
                DB::table('hr_job_requisitions')->insert([
                    'id' => $id, 'company_id' => $company, 'position_id' => $position->id,
                    'organization_unit_id' => $d['organization_unit_id'] ?? null, 'code' => trim($d['code']),
                    'title' => trim($d['title']), 'headcount' => $d['headcount'],
                    'employment_type_code' => $d['employment_type_code'] ?? null,
                    'requirements' => json_encode(array_values($d['requirements']), JSON_THROW_ON_ERROR),
                    'budget_snapshot' => isset($d['budget_snapshot']) ? json_encode($d['budget_snapshot'], JSON_THROW_ON_ERROR) : null,
                    'target_date' => $d['target_date'] ?? null, 'status' => 'pending_approval',
                    'requested_by' => $r->user()->id, 'idempotency_key' => $d['idempotency_key'],
                    'request_payload_checksum' => $checksum, 'created_at' => now(), 'updated_at' => now(),
                ]);
                activity('hr-recruitment')->causedBy($r->user())->withProperties([
                    'requisition_id' => $id, 'company_id' => $company,
                ])->log('job_requisition_submitted');

                return response()->json(['status' => 'success', 'data' => ['id' => $id, 'status' => 'pending_approval']], 201);
            });
        } catch (QueryException $e) {
            $existing = DB::table('hr_job_requisitions')->where('company_id', $company)
                ->where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                    'This requisition key was already used with different request facts.');
                return response()->json(['status' => 'success', 'data' => ['id' => $existing->id, 'status' => $existing->status]]);
            }
            if (DB::table('hr_job_requisitions')->where('code', trim($d['code']))->exists()) {
                abort(409, 'A requisition already uses this code.');
            }
            throw $e;
        }
    }
    public function approveRequisition(Request$r,string$id):JsonResponse{return DB::transaction(function()use($r,$id){$row=DB::table('hr_job_requisitions')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);$this->company($r,$row->company_id);abort_if($row->requested_by===$r->user()->id,409,'Requester cannot approve the same requisition.');abort_unless($row->status==='pending_approval',409);DB::table('hr_job_requisitions')->where('id',$id)->update(['status'=>'approved','approved_by'=>$r->user()->id,'approved_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_job_requisitions')->find($id)]);});}
    public function candidates(Request$r):JsonResponse{$company=$this->company($r,$r->input('company_id'));$rows=DB::table('hr_candidates')->where('company_id',$company)->select(['id','candidate_code','source_type','source_reference','consent_at','consent_expires_at','status','created_at'])->latest()->paginate($r->integer('per_page',50));return response()->json(['status'=>'success','data'=>$rows]);}
    public function storeCandidate(Request$r):JsonResponse{$d=$r->validate(['company_id'=>['nullable','uuid'],'profile'=>['required','array'],'profile.first_name'=>['required','string','max:120'],'profile.last_name'=>['nullable','string','max:120'],'profile.email'=>['required','email','max:255'],'profile.phone'=>['nullable','string','max:40'],'profile.nic'=>['nullable','string','max:40'],'profile.cv_document_id'=>['nullable','uuid'],'source_type'=>['required',Rule::in(['career_site','referral','agency','direct','talent_pool','import'])],'source_reference'=>['nullable','string','max:160'],'consent_at'=>['required','date'],'consent_expires_at'=>['nullable','date','after:consent_at']]);$d['company_id']=$this->company($r,$d['company_id']??null);$fingerprint=hash('sha256',strtolower(trim($d['profile']['email'])).'|'.preg_replace('/\W/','',$d['profile']['nic']??''));if($duplicate=DB::table('hr_candidates')->where('company_id',$d['company_id'])->where('identity_fingerprint',$fingerprint)->first())return response()->json(['status'=>'conflict','message'=>'A potential duplicate candidate requires review.','data'=>['candidate_id'=>$duplicate->id]],409);$id=(string)Str::uuid();DB::table('hr_candidates')->insert(['id'=>$id,'company_id'=>$d['company_id'],'candidate_code'=>'CAN-'.strtoupper(Str::random(12)),'encrypted_profile'=>Crypt::encryptString(json_encode($d['profile'],JSON_THROW_ON_ERROR)),'identity_fingerprint'=>$fingerprint,'source_type'=>$d['source_type'],'source_reference'=>$d['source_reference']??null,'consent_at'=>$d['consent_at'],'consent_expires_at'=>$d['consent_expires_at']??null,'status'=>'active','created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_candidates')->select(['id','candidate_code','status'])->find($id)],201);}
    public function apply(Request $request): JsonResponse
    {
        
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid'],
            'candidate_id' => ['required', 'uuid', 'exists:hr_candidates,id'],
            'requisition_id' => ['required', 'uuid', 'exists:hr_job_requisitions,id'],
            'owner_staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
        ]);
        $companyId = $this->company($request, $data['company_id'] ?? null);
        $id = (string) Str::uuid();

        DB::transaction(function () use ($data, $id, $request, $companyId): void {
            $candidate = DB::table('hr_candidates')->where('id', $data['candidate_id'])
                ->where('company_id', $companyId)->lockForUpdate()->first();
            $requisition = DB::table('hr_job_requisitions')->where('id', $data['requisition_id'])
                ->where('company_id', $companyId)->lockForUpdate()->first();
            abort_unless($candidate && $requisition, 422, 'Application references must share one legal entity.');
            abort_unless($requisition->status === 'approved', 409, 'Requisition must be approved.');

            if (! empty($data['owner_staff_id'])) {
                $owner = DB::table('staff')->where('id', $data['owner_staff_id'])
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereNull('employment_ended_at')->lockForUpdate()->first();
                abort_unless($owner, 422, 'Application owner must be active Staff in the selected legal entity.');
            }

            DB::table('hr_candidate_applications')->insert($data + [
                'id' => $id, 'stage' => 'applied', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($id, 'applied', null, 'applied', 'Application created.', $request->user()->id, [
                'owner_staff_id' => $data['owner_staff_id'] ?? null,
            ]);
        });

        return response()->json(['status' => 'success', 'data' => DB::table('hr_candidate_applications')->find($id)], 201);
    }
    public function transition(Request$r,string$id):JsonResponse{$d=$r->validate(['stage'=>['required',Rule::in(['screening','shortlisted','interview','assessment','reference_check','offer','hired','rejected','withdrawn'])],'reason'=>['required','string','max:2000'],'disposition_code'=>['nullable','required_if:stage,rejected','string','max:80']]);return DB::transaction(function()use($r,$id,$d){$row=DB::table('hr_candidate_applications')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);$this->company($r,$row->company_id);abort_if(in_array($row->status,['converted','rejected','withdrawn'],true),409);$status=in_array($d['stage'],['rejected','withdrawn'],true)?$d['stage']:'active';$this->event($id,'stage_changed',$row->stage,$d['stage'],$d['reason'],$r->user()->id,$d);DB::table('hr_candidate_applications')->where('id',$id)->update(['stage'=>$d['stage'],'status'=>$status,'disposition_code'=>$d['disposition_code']??null,'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_candidate_applications')->find($id)]);});}
    public function storeOffer(Request$r,string$id):JsonResponse{$d=$r->validate(['version'=>['required','integer','min:1'],'package'=>['required','array'],'proposed_join_date'=>['required','date'],'expires_at'=>['required','date','after_or_equal:today']]);$app=DB::table('hr_candidate_applications')->find($id);abort_unless($app,404);$this->company($r,$app->company_id);abort_unless($app->stage==='offer',409,'Application must be in offer stage.');$offer=(string)Str::uuid();DB::table('hr_candidate_offers')->insert(['id'=>$offer,'application_id'=>$id,'version'=>$d['version'],'encrypted_package'=>Crypt::encryptString(json_encode($d['package'],JSON_THROW_ON_ERROR)),'proposed_join_date'=>$d['proposed_join_date'],'expires_at'=>$d['expires_at'],'status'=>'pending_approval','prepared_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_candidate_offers')->select(['id','application_id','version','status','proposed_join_date','expires_at'])->find($offer)],201);}
    public function decideOffer(Request$r,string$offerId):JsonResponse{$d=$r->validate(['action'=>['required',Rule::in(['approve','accept','decline'])],'reason'=>['nullable','required_if:action,decline','string','max:2000']]);return DB::transaction(function()use($r,$offerId,$d){$offer=DB::table('hr_candidate_offers')->where('id',$offerId)->lockForUpdate()->first();abort_unless($offer,404);$app=DB::table('hr_candidate_applications')->find($offer->application_id);$this->company($r,$app->company_id);if($d['action']==='approve'){abort_if($offer->prepared_by===$r->user()->id,409,'Offer preparer cannot approve it.');abort_unless($offer->status==='pending_approval',409);$update=['status'=>'approved','approved_by'=>$r->user()->id,'approved_at'=>now()];}else{abort_unless($offer->status==='approved',409,'Only an approved offer can receive a candidate response.');$update=['status'=>$d['action']==='accept'?'accepted':'declined','responded_at'=>now(),'response_reason'=>$d['reason']??null];DB::table('hr_candidate_applications')->where('id',$app->id)->update(['status'=>$d['action']==='accept'?'offer_accepted':'active','updated_at'=>now()]);}$update['updated_at']=now();DB::table('hr_candidate_offers')->where('id',$offerId)->update($update);return response()->json(['status'=>'success','data'=>DB::table('hr_candidate_offers')->select(['id','application_id','version','status','proposed_join_date','expires_at','approved_at','responded_at'])->find($offerId)]);});}
    /**
     * §5.14: "Recruitment analytics: time to hire, source effectiveness,
     * funnel conversion, offer acceptance." Read-only aggregation over the
     * existing requisition/candidate/application/event/offer tables; no
     * write path, no new schema.
     */
    public function analytics(Request$r):JsonResponse{
        $company=$this->company($r,$r->input('company_id'));
        $d=$r->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']]);
        $applications=DB::table('hr_candidate_applications as app')->join('hr_candidates as candidate','candidate.id','=','app.candidate_id')
            ->where('app.company_id',$company)->when($d['from']??null,fn($q,$v)=>$q->whereDate('app.created_at','>=',$v))->when($d['to']??null,fn($q,$v)=>$q->whereDate('app.created_at','<=',$v))
            ->select(['app.id','app.stage','app.status','app.created_at','candidate.source_type'])->get();
        $hiredEvents=DB::table('hr_candidate_application_events')->whereIn('application_id',$applications->pluck('id'))->where('to_stage','hired')
            ->orderBy('occurred_at')->get()->keyBy('application_id');
        $timeToHireDays=$applications->filter(fn($a)=>$hiredEvents->has($a->id))
            ->map(fn($a)=>CarbonImmutable::parse($a->created_at)->diffInDays(CarbonImmutable::parse($hiredEvents[$a->id]->occurred_at)));
        $bySource=$applications->groupBy('source_type')->map(function($group)use($hiredEvents){
            $hired=$group->filter(fn($a)=>$hiredEvents->has($a->id))->count();
            return['applications'=>$group->count(),'hired'=>$hired,'hire_rate_percent'=>$group->count()>0?round($hired/$group->count()*100,2):null];
        });
        $offers=DB::table('hr_candidate_offers as offer')->join('hr_candidate_applications as app','app.id','=','offer.application_id')
            ->where('app.company_id',$company)->when($d['from']??null,fn($q,$v)=>$q->whereDate('offer.created_at','>=',$v))->when($d['to']??null,fn($q,$v)=>$q->whereDate('offer.created_at','<=',$v))
            ->pluck('offer.status');
        $offersByStatus=$offers->countBy();$decided=(int)$offersByStatus->get('accepted',0)+(int)$offersByStatus->get('declined',0);
        return response()->json(['status'=>'success','data'=>[
            'total_applications'=>$applications->count(),'total_hired'=>$timeToHireDays->count(),
            'time_to_hire_days'=>['average'=>$timeToHireDays->isNotEmpty()?round($timeToHireDays->avg(),1):null,'median'=>$timeToHireDays->isNotEmpty()?round($timeToHireDays->median(),1):null,'hired_count'=>$timeToHireDays->count()],
            'funnel_by_stage'=>$applications->groupBy('stage')->map->count(),
            'source_effectiveness'=>$bySource,
            'offer_status_counts'=>$offersByStatus,
            'offer_acceptance_rate_percent'=>$decided>0?round((int)$offersByStatus->get('accepted',0)/$decided*100,2):null,
        ]]);
    }

    public function conversion(Request$r,string$id):JsonResponse{$app=DB::table('hr_candidate_applications')->find($id);abort_unless($app,404);$this->company($r,$app->company_id);abort_unless($app->status==='offer_accepted'&&!$app->converted_staff_id,409,'Application is not ready for conversion.');$candidate=DB::table('hr_candidates')->find($app->candidate_id);$req=DB::table('hr_job_requisitions')->find($app->requisition_id);return response()->json(['status'=>'success','data'=>['recruitment_application_id'=>$id,'candidate'=>json_decode(Crypt::decryptString($candidate->encrypted_profile),true,512,JSON_THROW_ON_ERROR),'requisition'=>['position_id'=>$req->position_id,'organization_unit_id'=>$req->organization_unit_id,'employment_type_code'=>$req->employment_type_code],'requires_existing_or_provisioned_user'=>true]]);}
    /**
     * §5.14 "Configurable hiring pipeline ... interviews" needs a way to find
     * applications to interview at all; no list route existed before this.
     * Deliberately mirrors candidates()'s privacy stance: candidate identity
     * stays behind candidate_code, never the encrypted profile.
     */
    public function applications(Request$r):JsonResponse{$company=$this->company($r,$r->input('company_id'));$rows=DB::table('hr_candidate_applications as app')->join('hr_job_requisitions as req','req.id','=','app.requisition_id')->join('hr_candidates as candidate','candidate.id','=','app.candidate_id')->where('app.company_id',$company)->when($r->filled('requisition_id'),fn($q)=>$q->where('app.requisition_id',$r->input('requisition_id')))->when($r->filled('stage'),fn($q)=>$q->where('app.stage',$r->input('stage')))->select(['app.id','app.candidate_id','candidate.candidate_code','app.requisition_id','req.code as requisition_code','req.title as requisition_title','app.stage','app.status','app.owner_staff_id','app.converted_staff_id','app.created_at'])->latest('app.created_at')->paginate($r->integer('per_page',50));return response()->json(['status'=>'success','data'=>$rows]);}
    /**
     * §5.14 "Interview scheduling integration boundary, panel feedback
     * confidentiality." `hr_candidate_interviews`/`hr_candidate_feedback`
     * already existed in the 2026-08-13 migration with no route ever built
     * against them. Schedule details stay encrypted at rest; they are only
     * decrypted here for a recruitment manager/approver or an assigned
     * panelist — never for an unrelated viewer with only `.view`.
     */
    public function interviews(Request$r,string$id):JsonResponse{$app=DB::table('hr_candidate_applications')->find($id);abort_unless($app,404);$this->company($r,$app->company_id);$staffId=$this->actor($r)->id;$privileged=$r->user()->can('hr.recruitment.manage')||$r->user()->can('hr.recruitment.approve');$interviewIds=DB::table('hr_candidate_interviews')->where('application_id',$id)->pluck('id');$feedbackCounts=DB::table('hr_candidate_feedback')->whereIn('interview_id',$interviewIds)->select('interview_id',DB::raw('count(*) as c'))->groupBy('interview_id')->pluck('c','interview_id');$rows=DB::table('hr_candidate_interviews')->where('application_id',$id)->orderByDesc('scheduled_at')->get()->map(function($row)use($privileged,$staffId,$feedbackCounts){$panel=json_decode($row->panel_staff_ids,true)??[];$isPanelist=$staffId&&in_array($staffId,$panel,true);return['id'=>$row->id,'application_id'=>$row->application_id,'interview_type'=>$row->interview_type,'scheduled_at'=>$row->scheduled_at,'timezone'=>$row->timezone,'status'=>$row->status,'panel_staff_ids'=>$panel,'panel_size'=>count($panel),'feedback_submitted_count'=>(int)($feedbackCounts[$row->id]??0),'my_feedback_submitted'=>$isPanelist?DB::table('hr_candidate_feedback')->where('interview_id',$row->id)->where('reviewer_staff_id',$staffId)->exists():null,'schedule_details'=>($privileged||$isPanelist)&&$row->encrypted_schedule_details?json_decode(Crypt::decryptString($row->encrypted_schedule_details),true,512,JSON_THROW_ON_ERROR):null,'created_at'=>$row->created_at];});return response()->json(['status'=>'success','data'=>$rows]);}
    public function interviewPanelOptions(Request$r):JsonResponse
    {
        $company=$this->company($r,null);
        $d=$r->validate(['search'=>['nullable','string','max:120'],'selected_ids'=>['nullable','array','max:50'],'selected_ids.*'=>['uuid','distinct'],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:1','max:50']]);
        $term=trim((string)($d['search']??''));
        $query=DB::table('staff')->leftJoin('users','users.id','=','staff.user_id')->where('staff.company_id',$company)
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')->whereExists(fn($spell)=>$spell->selectRaw('1')->from('hr_employment_spells')->whereColumn('hr_employment_spells.staff_id','staff.id')->where('hr_employment_spells.company_id',$company)->where('hr_employment_spells.status','active')->whereNull('hr_employment_spells.terminated_at'))
            ->when($d['selected_ids']??null,fn($q,$ids)=>$q->whereIn('staff.id',$ids))
            ->when($term!==''&&empty($d['selected_ids']),fn($q)=>$q->where(fn($match)=>$match->whereRaw('LOWER(staff.code) LIKE ?',['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(users.first_name) LIKE ?',['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(users.last_name) LIKE ?',['%'.mb_strtolower($term).'%'])))
            ->select(['staff.id','staff.code','staff.staff_type','users.first_name','users.last_name'])->orderBy('users.first_name')->orderBy('users.last_name')->orderBy('staff.id');
        $map=fn($row)=>['value'=>(string)$row->id,'label'=>trim(trim(($row->first_name??'').' '.($row->last_name??'')).' · '.($row->code?:'No Staff code')),'metadata'=>['staff_type'=>$row->staff_type],'status'=>'active'];
        if(!empty($d['selected_ids']))return response()->json(['status'=>'success','data'=>$query->get()->map($map)->values()]);
        $rows=$query->paginate((int)($d['per_page']??25));$rows->getCollection()->transform($map);
        return response()->json(['status'=>'success','data'=>$rows]);
    }

    public function storeInterview(Request$r,string$id):JsonResponse
    {
        
        $d=$r->validate(['interview_type'=>['required','string','max:60'],'scheduled_at'=>['required','date'],'timezone'=>['required','string','max:80'],'panel_staff_ids'=>['required','array','min:1','max:50'],'panel_staff_ids.*'=>['uuid','distinct'],'schedule_details'=>['nullable','array']]);
        return DB::transaction(function()use($r,$id,$d){
            $app=DB::table('hr_candidate_applications')->where('id',$id)->lockForUpdate()->first();abort_unless($app,404);$this->company($r,$app->company_id);
            abort_unless($app->stage==='interview'&&$app->status==='active',409,'Application must be active in the interview stage before scheduling an interview.');
            $panel=array_values(array_unique($d['panel_staff_ids']));
            $activePanel=DB::table('staff')->whereIn('id',$panel)->where('company_id',$app->company_id)->whereNull('deleted_at')->whereNull('employment_ended_at')
                ->whereExists(fn($spell)=>$spell->selectRaw('1')->from('hr_employment_spells')->whereColumn('hr_employment_spells.staff_id','staff.id')->where('hr_employment_spells.company_id',$app->company_id)->where('hr_employment_spells.status','active')->whereNull('hr_employment_spells.terminated_at'))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless($activePanel->count()===count($panel),422,'Every panel member must be active Staff with an active employment spell in the application legal entity.');
            $interviewId=(string)Str::uuid();
            DB::table('hr_candidate_interviews')->insert(['id'=>$interviewId,'application_id'=>$id,'interview_type'=>$d['interview_type'],'scheduled_at'=>$d['scheduled_at'],'timezone'=>$d['timezone'],'panel_staff_ids'=>json_encode($panel,JSON_THROW_ON_ERROR),'status'=>'scheduled','encrypted_schedule_details'=>isset($d['schedule_details'])?Crypt::encryptString(json_encode($d['schedule_details'],JSON_THROW_ON_ERROR)):null,'created_at'=>now(),'updated_at'=>now()]);
            $this->event($id,'interview_scheduled',$app->stage,$app->stage,'Interview scheduled.',$r->user()->id,['interview_id'=>$interviewId,'interview_type'=>$d['interview_type'],'scheduled_at'=>$d['scheduled_at'],'panel_staff_ids'=>$panel]);
            return response()->json(['status'=>'success','data'=>DB::table('hr_candidate_interviews')->select(['id','application_id','interview_type','scheduled_at','timezone','status'])->find($interviewId)],201);
        });
    }
    public function interviewAction(Request$r,string$interviewId):JsonResponse{$d=$r->validate(['action'=>['required',Rule::in(['reschedule','cancel','complete','no_show'])],'reason'=>['required','string','max:2000'],'scheduled_at'=>['required_if:action,reschedule','nullable','date'],'timezone'=>['nullable','string','max:80']]);return DB::transaction(function()use($r,$interviewId,$d){$interview=DB::table('hr_candidate_interviews')->where('id',$interviewId)->lockForUpdate()->first();abort_unless($interview,404);$app=DB::table('hr_candidate_applications')->find($interview->application_id);$this->company($r,$app->company_id);abort_unless($interview->status==='scheduled',409,'Only a scheduled interview can be updated.');$update=['updated_at'=>now()];if($d['action']==='reschedule'){$update['scheduled_at']=$d['scheduled_at'];if(!empty($d['timezone']))$update['timezone']=$d['timezone'];}else{$update['status']=['cancel'=>'cancelled','complete'=>'completed','no_show'=>'no_show'][$d['action']];}DB::table('hr_candidate_interviews')->where('id',$interviewId)->update($update);$this->event($app->id,'interview_'.$d['action'],$app->stage,$app->stage,$d['reason'],$r->user()->id,['interview_id'=>$interviewId]+$update);return response()->json(['status'=>'success','data'=>DB::table('hr_candidate_interviews')->select(['id','application_id','interview_type','scheduled_at','timezone','status'])->find($interviewId)]);});}
    /**
     * Self-scoped like `hr/ess/my-requests`: any panelist can discover and
     * act on interviews they are assigned to without holding a recruitment
     * department permission. Panel membership itself (checked at schedule
     * time against the application's legal entity) is the authorization.
     */
    public function myInterviews(Request$r):JsonResponse{$staff=$this->actor($r);$this->company($r,$staff->company_id);$rows=DB::table('hr_candidate_interviews as interview')->join('hr_candidate_applications as app','app.id','=','interview.application_id')->where('app.company_id',$staff->company_id)->whereJsonContains('interview.panel_staff_ids',$staff->id)->orderByDesc('interview.scheduled_at')->get(['interview.*'])->map(function($row)use($staff){$feedback=DB::table('hr_candidate_feedback')->where('interview_id',$row->id)->where('reviewer_staff_id',$staff->id)->first();return['id'=>$row->id,'application_id'=>$row->application_id,'interview_type'=>$row->interview_type,'scheduled_at'=>$row->scheduled_at,'timezone'=>$row->timezone,'status'=>$row->status,'schedule_details'=>$row->encrypted_schedule_details?json_decode(Crypt::decryptString($row->encrypted_schedule_details),true,512,JSON_THROW_ON_ERROR):null,'my_feedback_submitted'=>(bool)$feedback,'my_recommendation'=>$feedback->recommendation??null];});return response()->json(['status'=>'success','data'=>$rows]);}
    public function storeInterviewFeedback(Request$r,string$interviewId):JsonResponse{$d=$r->validate(['recommendation'=>['required',Rule::in(['strong_hire','hire','no_hire','strong_no_hire'])],'scorecard'=>['required','array']]);$interview=DB::table('hr_candidate_interviews')->find($interviewId);abort_unless($interview,404);$app=DB::table('hr_candidate_applications')->find($interview->application_id);$this->company($r,$app->company_id);abort_unless($interview->status!=='cancelled',409,'A cancelled interview cannot receive feedback.');$staffId=$this->actor($r)->id;$panel=json_decode($interview->panel_staff_ids,true)??[];abort_unless(in_array($staffId,$panel,true),403,'Only an assigned panel member may submit feedback for this interview.');abort_if(DB::table('hr_candidate_feedback')->where('interview_id',$interviewId)->where('reviewer_staff_id',$staffId)->exists(),409,'Feedback was already submitted for this interview.');$id=(string)Str::uuid();DB::table('hr_candidate_feedback')->insert(['id'=>$id,'interview_id'=>$interviewId,'reviewer_staff_id'=>$staffId,'encrypted_scorecard'=>Crypt::encryptString(json_encode($d['scorecard'],JSON_THROW_ON_ERROR)),'recommendation'=>$d['recommendation'],'submitted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);$this->event($app->id,'interview_feedback_submitted',$app->stage,$app->stage,'Panel feedback submitted.',$r->user()->id,['interview_id'=>$interviewId,'reviewer_staff_id'=>$staffId,'recommendation'=>$d['recommendation']]);return response()->json(['status'=>'success','data'=>['id'=>$id,'interview_id'=>$interviewId,'recommendation'=>$d['recommendation']]],201);}
    /**
     * Confidential aggregate view: gated by `hr.recruitment.approve` at the
     * route so an individual scorecard is never visible to a plain
     * `.manage`/`.view` holder, only to someone authorized to decide offers.
     */
    public function interviewFeedback(Request$r,string$interviewId):JsonResponse{$interview=DB::table('hr_candidate_interviews')->find($interviewId);abort_unless($interview,404);$app=DB::table('hr_candidate_applications')->find($interview->application_id);$this->company($r,$app->company_id);$rows=DB::table('hr_candidate_feedback as f')->leftJoin('staff as s','s.id','=','f.reviewer_staff_id')->leftJoin('users as u','u.id','=','s.user_id')->where('f.interview_id',$interviewId)->orderBy('f.submitted_at')->select(['f.*','s.code as reviewer_staff_code','u.first_name as reviewer_first_name','u.last_name as reviewer_last_name'])->get()->map(fn($row)=>['id'=>$row->id,'reviewer_staff_id'=>$row->reviewer_staff_id,'reviewer_staff_code'=>$row->reviewer_staff_code,'reviewer_first_name'=>$row->reviewer_first_name,'reviewer_last_name'=>$row->reviewer_last_name,'recommendation'=>$row->recommendation,'scorecard'=>json_decode(Crypt::decryptString($row->encrypted_scorecard),true,512,JSON_THROW_ON_ERROR),'submitted_at'=>$row->submitted_at]);return response()->json(['status'=>'success','data'=>$rows]);}
    private function event(string$id,string$type,?string$from,string$to,string$reason,string$actor,array$snapshot):void{DB::table('hr_candidate_application_events')->insert(['id'=>(string)Str::uuid(),'application_id'=>$id,'event_type'=>$type,'from_stage'=>$from,'to_stage'=>$to,'reason'=>$reason,'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function actor(Request$r):Staff{return app(StaffAccessService::class)->currentActorStaff($r->user());}
    private function company(Request$r,?string$id):string{$actor=$this->actor($r)->company_id;abort_unless($actor&&(!$id||$actor===$id),403,'Recruitment data is outside your legal entity.');abort_unless(DB::table('companies')->where('id',$actor)->where('is_active',true)->whereNull('deleted_at')->exists(),404,'Recruitment legal entity is unavailable.');return$actor;}
    
}
