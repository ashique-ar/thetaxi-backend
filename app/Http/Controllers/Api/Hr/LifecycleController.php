<?php
namespace App\Http\Controllers\Api\Hr;
use App\Http\Controllers\Controller;use App\Models\Staff;use App\Services\Hr\Lifecycle\LifecycleService;use App\Services\StaffAccessService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;use Illuminate\Validation\Rule;
class LifecycleController extends Controller{
 public function cases(Request $r, StaffAccessService $access): JsonResponse
 {
     $companyId = $this->actorCompanyId($r);
     $staff = $access->scope(Staff::query(), $r->user())->where('company_id', $companyId)->select('id');
     $rows = DB::table('hr_lifecycle_cases as c')->leftJoin('staff as s', fn ($join) => $join->on('s.id', '=', 'c.staff_id')->on('s.company_id', '=', 'c.company_id'))
         ->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('c.company_id', $companyId)
         ->where(function ($visibility) use ($staff) {
             $visibility->whereIn('c.staff_id', $staff)
                 ->orWhere(function ($preHire) {
                     $preHire->whereNotNull('c.application_id')->whereExists(fn ($application) => $application
                         ->selectRaw('1')->from('hr_candidate_applications as a')
                         ->whereColumn('a.id', 'c.application_id')->whereColumn('a.company_id', 'c.company_id'));
                 });
         })
         ->select(['c.id', 'c.company_id', 'c.staff_id', 'c.application_id', 'c.template_id', 'c.case_type', 'c.status', 'c.effective_date', 'c.case_snapshot', 'c.opened_by', 'c.closed_at', 'c.closed_by', 'c.created_at', 'c.updated_at', 's.code as staff_code', 'u.first_name as staff_first_name', 'u.last_name as staff_last_name'])
         ->latest('c.created_at')->paginate($r->integer('per_page', 50));

     $caseIds = $rows->getCollection()->pluck('id');
     $tasks = DB::table('hr_lifecycle_tasks')->whereIn('case_id', $caseIds)
         ->select(['id', 'case_id', 'task_code', 'title', 'owner_kind', 'due_date', 'dependency_codes', 'status'])
         ->orderBy('created_at')->get()->groupBy('case_id');
     $rows->getCollection()->transform(function ($case) use ($tasks) {
         $case->tasks = $tasks->get($case->id, collect())->map(function ($task) {
             $task->dependency_codes = json_decode($task->dependency_codes ?: '[]', true, 512, JSON_THROW_ON_ERROR);
             return $task;
         })->values();
         return $case;
     });

     return response()->json(['status' => 'success', 'data' => $rows]);
 }
 public function clearanceItems(Request $r): JsonResponse
 {
     $companyId = $this->actorCompanyId($r);
     $rows = DB::table('hr_exit_clearance_items as item')
         ->join('hr_exit_cases as exit_case', 'exit_case.id', '=', 'item.exit_case_id')
         ->leftJoin('staff', fn ($join) => $join->on('staff.id', '=', 'exit_case.staff_id')->on('staff.company_id', '=', 'exit_case.company_id'))
         ->leftJoin('users', 'users.id', '=', 'staff.user_id')
         ->where('exit_case.company_id', $companyId)
         ->select(['item.id', 'item.exit_case_id', 'item.clearance_type', 'item.title', 'item.status', 'item.completed_at', 'staff.code as staff_code', 'users.first_name', 'users.last_name'])
         ->latest('item.created_at')->paginate($r->integer('per_page', 50));

     return response()->json(['status' => 'success', 'data' => $rows]);
 }
 public function probationCases(Request $r): JsonResponse
 {
     $companyId = $this->actorCompanyId($r);
     $rows = DB::table('hr_probation_cases as probation')
         ->join('hr_employment_spells as spell', 'spell.id', '=', 'probation.employment_spell_id')
         ->join('staff', fn ($join) => $join->on('staff.id', '=', 'probation.staff_id')->on('staff.company_id', '=', 'spell.company_id'))
         ->leftJoin('users', 'users.id', '=', 'staff.user_id')
         ->where('spell.company_id', $companyId)
         ->whereColumn('spell.staff_id', 'probation.staff_id')
         ->select(['probation.id', 'probation.starts_at', 'probation.review_due_at', 'probation.current_end_at', 'probation.status', 'probation.proposed_outcome', 'probation.final_outcome', 'users.first_name', 'users.last_name', 'staff.code as staff_code'])
         ->latest('probation.created_at')->paginate($r->integer('per_page', 50));

     return response()->json(['status' => 'success', 'data' => $rows]);
 }
 /**
  * §5.15/QH5-01: storeTemplate()/approveTemplate() were POST-only with no route to list a
  * template — so openCase()'s required template_id (which must reference an *approved*
  * template per LifecycleService::openCase()) had no way to be discovered by a caller,
  * and a pending template had no way to be found to approve it. The same "create exists,
  * no read-back" gap already closed for Attendance/Leave configuration.
  */
 public function templates(Request$r):JsonResponse{$companyId=$this->actorCompanyId($r);$q=DB::table('hr_lifecycle_templates')->where('company_id',$companyId)->select(['id', 'company_id', 'case_type', 'code', 'version', 'applicability', 'task_definitions', 'status', 'created_by', 'approved_by', 'approved_at', 'created_at', 'updated_at'])->when($r->case_type,fn($b,$v)=>$b->where('case_type',$v))->when($r->status,fn($b,$v)=>$b->where('status',$v))->latest('created_at');$rows=$q->paginate($r->integer('per_page',50));$rows->getCollection()->transform(fn($row)=>array_merge((array)$row,['applicability'=>json_decode($row->applicability,true,512,JSON_THROW_ON_ERROR),'task_definitions'=>json_decode($row->task_definitions,true,512,JSON_THROW_ON_ERROR)]));return response()->json(['status'=>'success','data'=>$rows]);}
 public function templateOptions(Request$r):JsonResponse{$d=$r->validate(['company_id'=>['nullable','uuid'],'search'=>['nullable','string','max:120'],'selected_id'=>['nullable','uuid'],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:1','max:50']]);$d['company_id']=$d['company_id']??$this->actorCompanyId($r);$this->company($r,$d['company_id']);$q=DB::table('hr_lifecycle_templates')->where('company_id',$d['company_id'])->where('status','approved');if(!empty($d['selected_id']))$q->where('id',$d['selected_id']);elseif(!empty($d['search'])){$term='%'.strtolower(addcslashes(trim($d['search']),'%_\\')).'%';$q->where(fn($match)=>$match->whereRaw('LOWER(code) LIKE ?',[$term])->orWhereRaw('LOWER(case_type) LIKE ?',[$term]));}$q->select(['id','code','version','case_type'])->orderBy('code')->orderByDesc('version')->orderBy('id');$map=fn($template)=>['value'=>(string)$template->id,'label'=>$template->code.' v'.$template->version.' ('.$template->case_type.')','metadata'=>['case_type'=>$template->case_type],'status'=>'approved'];if(!empty($d['selected_id']))return response()->json(['status'=>'success','data'=>$q->limit(1)->get()->map($map)->values()]);$rows=$q->paginate($d['per_page']??25);$rows->getCollection()->transform($map);return response()->json(['status'=>'success','data'=>$rows]);}
 public function caseSubjectOptions(Request$r):JsonResponse{$d=$r->validate(['company_id'=>['nullable','uuid'],'record_type'=>['required',Rule::in(['staff','application'])],'search'=>['nullable','string','max:120'],'selected_id'=>['nullable','uuid'],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:1','max:50']]);$d['company_id']=$d['company_id']??$this->actorCompanyId($r);$this->company($r,$d['company_id']);$term=trim((string)($d['search']??''));if($d['record_type']==='staff'){$q=DB::table('staff')->leftJoin('users','users.id','=','staff.user_id')->where('staff.company_id',$d['company_id'])->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')->when($d['selected_id']??null,fn($b,$id)=>$b->where('staff.id',$id))->when($term!==''&&empty($d['selected_id']),fn($b)=>$b->where(fn($m)=>$m->whereRaw('LOWER(staff.code) LIKE ?',['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(users.first_name) LIKE ?',['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(users.last_name) LIKE ?',['%'.mb_strtolower($term).'%'])))->select(['staff.id','staff.code','staff.staff_type','users.first_name','users.last_name'])->orderBy('users.first_name')->orderBy('staff.id');$map=fn($row)=>['value'=>(string)$row->id,'label'=>trim(trim(($row->first_name??'').' '.($row->last_name??'')).' · '.($row->code?:'No Staff code')),'metadata'=>['staff_type'=>$row->staff_type],'status'=>'active'];}else{$q=DB::table('hr_candidate_applications as app')->join('hr_candidates as candidate','candidate.id','=','app.candidate_id')->join('hr_job_requisitions as req','req.id','=','app.requisition_id')->where('app.company_id',$d['company_id'])->where('app.status','active')->when($d['selected_id']??null,fn($b,$id)=>$b->where('app.id',$id))->when($term!==''&&empty($d['selected_id']),fn($b)=>$b->where(fn($m)=>$m->whereRaw('LOWER(candidate.candidate_code) LIKE ?',['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(req.code) LIKE ?',['%'.mb_strtolower($term).'%'])->orWhereRaw('LOWER(req.title) LIKE ?',['%'.mb_strtolower($term).'%'])))->select(['app.id','app.stage','candidate.candidate_code','req.code as requisition_code','req.title as requisition_title'])->orderBy('candidate.candidate_code')->orderBy('app.id');$map=fn($row)=>['value'=>(string)$row->id,'label'=>$row->candidate_code.' · '.$row->requisition_title,'metadata'=>['requisition'=>$row->requisition_code,'stage'=>$row->stage],'status'=>'active'];}$rows=$q->paginate((int)($d['per_page']??25));$rows->getCollection()->transform($map);return response()->json(['status'=>'success','data'=>$rows]);}
 public function storeTemplate(Request $r): JsonResponse
 {
     
     $data = $r->validate([
         'company_id' => ['nullable', 'uuid'], 'case_type' => ['required', Rule::in(['preboarding', 'onboarding', 'probation', 'transfer', 'offboarding'])],
         'code' => ['required', 'string', 'max:80'], 'version' => ['required', 'integer', 'min:1'],
         'applicability' => ['required', 'array'], 'task_definitions' => ['required', 'array', 'min:1'],
         'task_definitions.*.code' => ['required', 'string', 'max:80'], 'task_definitions.*.title' => ['required', 'string', 'max:255'],
         'idempotency_key' => ['required', 'uuid'],
     ]);
     $data['company_id']=$data['company_id']??$this->actorCompanyId($r);$this->company($r, $data['company_id']);
     $data['code'] = trim($data['code']);
     $key = $data['idempotency_key'];
     unset($data['idempotency_key']);
     $checksum = hash('sha256', json_encode([
         'company_id' => $data['company_id'], 'case_type' => $data['case_type'], 'code' => $data['code'],
         'version' => (int) $data['version'], 'applicability' => $data['applicability'],
         'task_definitions' => array_values($data['task_definitions']),
     ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

     try {
         return DB::transaction(function () use ($r, $data, $key, $checksum): JsonResponse {
             $existing = DB::table('hr_lifecycle_templates')->where('company_id', $data['company_id'])
                 ->where('idempotency_key', $key)->lockForUpdate()->first();
             if ($existing) {
                 abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                     'This lifecycle template key was already used with different request facts.');
                 return response()->json(['status' => 'success', 'data' => [
                     'id' => $existing->id, 'company_id' => $existing->company_id, 'case_type' => $existing->case_type,
                     'code' => $existing->code, 'version' => $existing->version, 'status' => $existing->status,
                 ]]);
             }
             abort_if(DB::table('hr_lifecycle_templates')->where('company_id', $data['company_id'])
                 ->where('code', $data['code'])->where('version', $data['version'])->exists(), 409,
                 'A lifecycle template with this company, code, and version already exists.');

             $id = (string) Str::uuid();
             DB::table('hr_lifecycle_templates')->insert([
                 'id' => $id, 'company_id' => $data['company_id'], 'case_type' => $data['case_type'],
                 'code' => $data['code'], 'version' => $data['version'],
                 'applicability' => json_encode($data['applicability'], JSON_THROW_ON_ERROR),
                 'task_definitions' => json_encode(array_values($data['task_definitions']), JSON_THROW_ON_ERROR),
                 'status' => 'pending_approval', 'created_by' => $r->user()->id, 'approved_by' => null, 'approved_at' => null,
                 'idempotency_key' => $key, 'request_payload_checksum' => $checksum, 'created_at' => now(), 'updated_at' => now(),
             ]);
             activity('hr-lifecycle')->causedBy($r->user())->withProperties([
                 'template_id' => $id, 'company_id' => $data['company_id'], 'case_type' => $data['case_type'],
             ])->log('lifecycle_template_submitted');

             return response()->json(['status' => 'success', 'data' => [
                 'id' => $id, 'company_id' => $data['company_id'], 'case_type' => $data['case_type'],
                 'code' => $data['code'], 'version' => $data['version'], 'status' => 'pending_approval',
             ]], 201);
         });
     } catch (\Illuminate\Database\QueryException $e) {
         $existing = DB::table('hr_lifecycle_templates')->where('company_id', $data['company_id'])
             ->where('idempotency_key', $key)->first();
         if ($existing) {
             abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                 'This lifecycle template key was already used with different request facts.');
             return response()->json(['status' => 'success', 'data' => [
                 'id' => $existing->id, 'company_id' => $existing->company_id, 'case_type' => $existing->case_type,
                 'code' => $existing->code, 'version' => $existing->version, 'status' => $existing->status,
             ]]);
         }
         if (DB::table('hr_lifecycle_templates')->where('company_id', $data['company_id'])
             ->where('code', $data['code'])->where('version', $data['version'])->exists()) {
             abort(409, 'A lifecycle template with this company, code, and version already exists.');
         }
         throw $e;
     }
 }
 public function approveTemplate(Request$r,string$id):JsonResponse{return DB::transaction(function()use($r,$id){$row=DB::table('hr_lifecycle_templates')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);$this->company($r,$row->company_id);abort_if($row->created_by===$r->user()->id,409,'Template creator cannot approve it.');abort_unless($row->status==='pending_approval',409);DB::table('hr_lifecycle_templates')->where('id',$id)->update(['status'=>'approved','approved_by'=>$r->user()->id,'approved_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_lifecycle_templates')->select(['id','company_id','case_type','code','version','status','created_by','approved_by','approved_at','created_at','updated_at'])->find($id)]);});}
 public function openCase(Request $r, LifecycleService $s): JsonResponse
 {
     
     $data = $r->validate([
         'company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid', 'required_without:application_id', 'prohibited_with:application_id'],
         'application_id' => ['nullable', 'uuid', 'required_without:staff_id', 'prohibited_with:staff_id'],
         'template_id' => ['required', 'uuid'], 'effective_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'],
     ]);
     $data['company_id']=$data['company_id']??$this->actorCompanyId($r);$this->company($r, $data['company_id']);
     $key = $data['idempotency_key'];
     unset($data['idempotency_key']);
     $checksum = hash('sha256', json_encode([
         'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'] ?? null,
         'application_id' => $data['application_id'] ?? null, 'template_id' => $data['template_id'],
         'effective_date' => $data['effective_date'],
     ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

     try {
         return DB::transaction(function () use ($r, $s, $data, $key, $checksum): JsonResponse {
             $existing = DB::table('hr_lifecycle_cases')->where('company_id', $data['company_id'])
                 ->where('idempotency_key', $key)->lockForUpdate()->first();
             if ($existing) {
                 abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                     'This lifecycle case key was already used with different request facts.');
                 return response()->json(['status' => 'success', 'data' => [
                     'id' => $existing->id, 'status' => $existing->status,
                     'case_type' => $existing->case_type, 'effective_date' => $existing->effective_date,
                 ]]);
             }

             $case = $s->openCase($data, $r->user()->id);
             DB::table('hr_lifecycle_cases')->where('id', $case->id)->update([
                 'idempotency_key' => $key, 'request_payload_checksum' => $checksum,
             ]);
             activity('hr-lifecycle')->causedBy($r->user())->withProperties([
                 'case_id' => $case->id, 'company_id' => $data['company_id'], 'case_type' => $case->case_type,
             ])->log('lifecycle_case_opened');

             return response()->json(['status' => 'success', 'data' => [
                 'id' => $case->id, 'status' => $case->status,
                 'case_type' => $case->case_type, 'effective_date' => $case->effective_date,
             ]], 201);
         });
     } catch (\Illuminate\Database\QueryException $e) {
         $existing = DB::table('hr_lifecycle_cases')->where('company_id', $data['company_id'])
             ->where('idempotency_key', $key)->first();
         if ($existing) {
             abort_unless(hash_equals((string) $existing->request_payload_checksum, $checksum), 409,
                 'This lifecycle case key was already used with different request facts.');
             return response()->json(['status' => 'success', 'data' => [
                 'id' => $existing->id, 'status' => $existing->status,
                 'case_type' => $existing->case_type, 'effective_date' => $existing->effective_date,
             ]]);
         }
         throw $e;
     }
 }
 public function completeTask(Request$r,string$id,LifecycleService$s):JsonResponse{$d=$r->validate(['evidence'=>['required','array','min:1'],'idempotency_key'=>['required','uuid']]);$result=$s->completeTask($id,$d['evidence'],$r->user()->id,$this->actorCompanyId($r),$d['idempotency_key']);return response()->json(['status'=>'success','data'=>$result['task'],'idempotent_replay'=>$result['replayed']]);}
 public function startProbation(Request $request): JsonResponse
 {
     
     $data = $request->validate([
         'staff_id' => ['required', 'uuid', 'exists:staff,id'],
         'starts_at' => ['required', 'date'],
         'review_due_at' => ['required', 'date', 'after_or_equal:starts_at'],
         'current_end_at' => ['required', 'date', 'after_or_equal:review_due_at'],
         'objectives' => ['required', 'array', 'min:1'],
     ]);

     return DB::transaction(function () use ($request, $data): JsonResponse {
         $staff = Staff::query()->whereKey($data['staff_id'])
             ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
             ->lockForUpdate()->firstOrFail();
         $this->company($request, $staff->company_id);

         $spell = DB::table('hr_employment_spells')->where('staff_id', $staff->id)
             ->where('company_id', $staff->company_id)->where('status', 'active')->whereNull('terminated_at')
             ->lockForUpdate()->first();
         abort_unless($spell, 422, 'An active employment spell in the Staff legal entity is required.');
         abort_if(
             DB::table('hr_probation_cases')->where('employment_spell_id', $spell->id)->where('status', 'active')->exists(),
             409,
             'An active probation case already exists.'
         );

         $id = (string) Str::uuid();
         DB::table('hr_probation_cases')->insert([
             'id' => $id, 'staff_id' => $staff->id, 'employment_spell_id' => $spell->id,
             'starts_at' => $data['starts_at'], 'review_due_at' => $data['review_due_at'],
             'current_end_at' => $data['current_end_at'], 'objectives' => json_encode($data['objectives'], JSON_THROW_ON_ERROR),
             'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
         ]);

         return response()->json(['status' => 'success', 'data' => DB::table('hr_probation_cases')->find($id)], 201);
     });
 }
 public function decideProbation(Request $r, string $id): JsonResponse
 {
     
     $data = $r->validate([
         'outcome' => ['required', Rule::in(['confirmed', 'extended', 'separated'])],
         'new_end_at' => ['nullable', 'required_if:outcome,extended', 'prohibited_unless:outcome,extended', 'date_format:Y-m-d'],
         'reason' => ['required', 'string', 'max:2000'],
     ]);

     $result = DB::transaction(function () use ($r, $id, $data) {
         $row = DB::table('hr_probation_cases')->where('id', $id)->lockForUpdate()->first();
         abort_unless($row, 404);
         $companyId = $this->actorCompanyId($r);
         $staff = Staff::query()->whereKey($row->staff_id)->where('company_id', $companyId)->firstOrFail();
         $spell = DB::table('hr_employment_spells')->where('id', $row->employment_spell_id)
             ->where('staff_id', $staff->id)->where('company_id', $companyId)->lockForUpdate()->first();
         abort_unless($spell, 409, 'The probation employment spell does not match this Staff legal entity.');
         $summary = fn () => DB::table('hr_probation_cases')->select([
             'id', 'starts_at', 'review_due_at', 'current_end_at', 'status', 'proposed_outcome', 'final_outcome', 'decided_at',
         ])->findOrFail($id);

         if ($data['outcome'] === 'extended' && $row->proposed_outcome === 'extended'
             && $row->current_end_at === $data['new_end_at']) {
             abort_unless($row->status === 'active' && $row->decided_by === $r->user()->id
                 && hash_equals((string) $row->decision_reason, $data['reason']), 409,
                 'This probation extension conflicts with the saved decision.');
             return ['data' => $summary(), 'replayed' => true];
         }

         if ($row->status === 'completed') {
             abort_unless($row->proposed_outcome === $data['outcome']
                 && $row->decided_by === $r->user()->id
                 && hash_equals((string) $row->decision_reason, $data['reason']), 409,
                 'This probation decision conflicts with the saved outcome.');
             return ['data' => $summary(), 'replayed' => true];
         }

         abort_unless($row->status === 'active', 409);
         abort_unless($spell->status === 'active' && $spell->terminated_at === null, 409,
             'An active employment spell is required to make a probation decision.');
         if ($data['outcome'] === 'extended') {
             abort_unless($data['new_end_at'] > $row->current_end_at, 422, 'Probation extension must move the end date forward.');
             DB::table('hr_probation_cases')->where('id', $id)->update([
                 'current_end_at' => $data['new_end_at'], 'proposed_outcome' => 'extended',
                 'decision_reason' => $data['reason'], 'decided_by' => $r->user()->id,
                 'decided_at' => now(), 'updated_at' => now(),
             ]);
         } else {
             DB::table('hr_probation_cases')->where('id', $id)->update([
                 'status' => 'completed', 'proposed_outcome' => $data['outcome'], 'final_outcome' => $data['outcome'],
                 'decision_reason' => $data['reason'], 'decided_by' => $r->user()->id,
                 'decided_at' => now(), 'updated_at' => now(),
             ]);
             if ($data['outcome'] === 'confirmed') {
                 DB::table('hr_employment_spells')->where('id', $row->employment_spell_id)
                     ->where('company_id', $companyId)->update(['confirmation_date' => now()->toDateString(), 'updated_at' => now()]);
             }
         }

         activity('hr-lifecycle')->causedBy($r->user())->withProperties([
             'probation_case_id' => $id, 'staff_id' => $staff->id, 'company_id' => $staff->company_id,
             'outcome' => $data['outcome'],
         ])->log('probation_decided');

         return ['data' => $summary(), 'replayed' => false];
     });

     return response()->json(['status' => 'success', 'data' => $result['data'], 'idempotent_replay' => $result['replayed']]);
 }
 public function assignCustody(Request$r):JsonResponse{$d=$r->validate(['company_id'=>['nullable','uuid'],'staff_id'=>['required','uuid'],'custody_type'=>['required',Rule::in(['asset','access','phone_sim'])],'item_code'=>['required','string','max:120'],'item_name'=>['required','string','max:255'],'details'=>['nullable','array'],'assigned_at'=>['required','date'],'due_back_at'=>['nullable','date','after_or_equal:assigned_at'],'condition_snapshot'=>['nullable','array']]);$d['company_id']=$d['company_id']??$this->actorCompanyId($r);$this->company($r,$d['company_id']);abort_if(true&&in_array($d['custody_type'],['asset','phone_sim'],true),409,'Use the governed advanced asset request, approval, and issue workflow.');abort_unless(Staff::query()->whereKey($d['staff_id'])->where('company_id',$d['company_id'])->exists(),422);$id=(string)Str::uuid();DB::table('hr_custody_assignments')->insert(['id'=>$id,'company_id'=>$d['company_id'],'staff_id'=>$d['staff_id'],'custody_type'=>$d['custody_type'],'item_code'=>$d['item_code'],'item_name'=>$d['item_name'],'encrypted_details'=>isset($d['details'])?encrypt($d['details']):null,'assigned_at'=>$d['assigned_at'],'due_back_at'=>$d['due_back_at']??null,'status'=>'assigned','condition_snapshot'=>json_encode($d['condition_snapshot']??null,JSON_THROW_ON_ERROR),'assigned_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_custody_assignments')->select(['id','staff_id','custody_type','item_code','item_name','assigned_at','due_back_at','status'])->find($id)],201);}
 public function requestChange(Request $r, string $staffId, LifecycleService $service, StaffAccessService $access): JsonResponse
 {
     
     $data = $r->validate([
         'change_type' => ['required', Rule::in(['transfer', 'promotion', 'manager_change', 'location_change', 'work_pattern_change'])],
         'effective_date' => ['required', 'date'],
         'proposed_snapshot' => ['required', 'array'],
         'impact_snapshot' => ['required', 'array'],
         'reason' => ['required', 'string', 'max:2000'],
         'approver_staff_id' => ['nullable', 'uuid'],
         'sla_hours' => ['nullable', 'integer', 'min:1'],
         'idempotency_key' => ['required', 'string', 'max:160'],
     ]);
     $staff = Staff::query()->findOrFail($staffId);
     $this->company($r, $staff->company_id);
     $access->authorize($r->user(), $staff, 'view');

     return response()->json(['status' => 'success', 'data' => $service->requestChange($staff, $data, $r->user()->id)], 201);
 }
 public function approveChange(Request$r,string$id,LifecycleService$s):JsonResponse{$row=DB::table('hr_employee_change_requests')->find($id);abort_unless($row,404);$this->company($r,$row->company_id);return response()->json(['status'=>'success','data'=>$s->approveChange($id,$r->user()->id,(string)$row->company_id)]);}
 public function openExit(Request$r,LifecycleService$s):JsonResponse{$d=$r->validate(['company_id'=>['nullable','uuid'],'staff_id'=>['required','uuid'],'exit_type'=>['required',Rule::in(['resignation','termination','retirement','death_in_service'])],'notice_date'=>['nullable','date'],'proposed_last_working_date'=>['required','date'],'reason_code'=>['required','string','max:80'],'protected_reason'=>['nullable','string','max:4000'],'impact_snapshot'=>['required','array'],'rehire_eligible'=>['nullable','boolean']]);$d['company_id']=$d['company_id']??$this->actorCompanyId($r);$this->company($r,$d['company_id']);abort_unless(Staff::query()->whereKey($d['staff_id'])->where('company_id',$d['company_id'])->exists(),422);$id=(string)Str::uuid();$d['impact_snapshot']=json_encode($d['impact_snapshot'],JSON_THROW_ON_ERROR);$d['protected_reason']=isset($d['protected_reason'])?encrypt($d['protected_reason']):null;DB::table('hr_exit_cases')->insert($d+['id'=>$id,'status'=>'pending_approval','opened_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);app(\App\Services\Hr\Ess\HrRequestIndexService::class)->register($d['company_id'],$d['staff_id'],'exit','exit_case',$id,'pending_approval','Employment exit request',null,48,$r->user()->id,['attendance'=>true,'leave'=>true,'payroll'=>true]);return response()->json(['status'=>'success','data'=>DB::table('hr_exit_cases')->select(['id','staff_id','exit_type','proposed_last_working_date','reason_code','status'])->find($id)],201);}
 public function approveExit(Request$r,string$id,LifecycleService$s):JsonResponse{$row=DB::table('hr_exit_cases')->find($id);abort_unless($row,404);$this->company($r,$row->company_id);return response()->json(['status'=>'success','data'=>$s->approveExit($id,$r->user()->id,(string)$row->company_id)]);}
 public function completeClearance(Request$r,string$id,LifecycleService$s):JsonResponse{$d=$r->validate(['resolution'=>['required','string','max:2000']]);$result=$s->completeClearance($id,$d['resolution'],$r->user()->id,$this->actorCompanyId($r));return response()->json(['status'=>'success','data'=>$result['item'],'idempotent_replay'=>$result['replayed']]);}
 public function finalizeExit(Request$r,string$id,LifecycleService$s):JsonResponse{$row=DB::table('hr_exit_cases')->find($id);abort_unless($row,404);$this->company($r,$row->company_id);return response()->json(['status'=>'success','data'=>$s->finalizeExit($id,$r->user(),(string)$row->company_id)]);}
 private function actorCompanyId(Request$r):string{$company=app(StaffAccessService::class)->currentActorStaff($r->user())->company_id??app(\App\Services\SingleCompanyScope::class)->activeDefaultCompany()?->id;abort_unless($company,409,'No active default company is configured.');return(string)$company;}private function company(Request$r,string$id):void{abort_unless($this->actorCompanyId($r)===$id,403,'Lifecycle data is outside your legal entity.');}
}
