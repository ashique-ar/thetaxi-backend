<?php
namespace App\Http\Controllers\Api\Hr;use App\Http\Controllers\Controller;use App\Models\Staff;use App\Services\Hr\HrNotificationService;use App\Services\StaffAccessService;use App\Support\ObservabilitySanitizer;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;use Illuminate\Validation\Rule;
class HrNotificationController extends Controller{
public function templates(Request$r):JsonResponse{$a=$this->actor($r);return response()->json(['status'=>'success','data'=>DB::table('hr_notification_template_versions')->where('company_id',$a->company_id)->select(['id','code','version','event_type','channel','allowed_placeholders','mandatory','effective_from','effective_until','status','template_checksum'])->orderBy('code')->orderByDesc('version')->paginate($r->integer('per_page',50))]);}
public function storeTemplate(Request$r):JsonResponse{$a=$this->actor($r);$d=$r->validate(['code'=>['required','string','max:100'],'version'=>['required','integer','min:1'],'event_type'=>['required','string','max:100'],'channel'=>['required',Rule::in(['in_app','email','sms'])],'subject_template'=>['nullable','required_if:channel,email','string','max:500'],'body_template'=>['required','string','max:20000'],'allowed_placeholders'=>['present','array'],'allowed_placeholders.*'=>['required','string','regex:/^[a-zA-Z0-9_.-]+$/','distinct'],'mandatory'=>['required','boolean'],'effective_from'=>['required','date'],'effective_until'=>['nullable','date','after_or_equal:effective_from']]);preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',($d['subject_template']??'').' '.$d['body_template'],$matches);abort_if(array_diff(array_unique($matches[1]),$d['allowed_placeholders']),422,'Every template placeholder must be explicitly allowed.');$checksum=hash('sha256',json_encode($d,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$id=(string)Str::uuid();$d['allowed_placeholders']=json_encode(array_values($d['allowed_placeholders']),JSON_THROW_ON_ERROR);DB::table('hr_notification_template_versions')->insert($d+['id'=>$id,'company_id'=>$a->company_id,'status'=>'pending_approval','template_checksum'=>$checksum,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>['id'=>$id,'status'=>'pending_approval']],201);}
public function approveTemplate(Request$r,string$id):JsonResponse{$a=$this->actor($r);return DB::transaction(function()use($r,$a,$id){$t=DB::table('hr_notification_template_versions')->where('id',$id)->where('company_id',$a->company_id)->lockForUpdate()->first();abort_unless($t,404);abort_unless($t->status==='pending_approval',409);abort_if($t->created_by===$r->user()->id,409,'Template creator cannot approve the same version.');$overlap=DB::table('hr_notification_template_versions')->where('company_id',$a->company_id)->where('event_type',$t->event_type)->where('channel',$t->channel)->where('status','approved')->whereDate('effective_from','<=',$t->effective_until??'9999-12-31')->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',$t->effective_from))->exists();abort_if($overlap,409,'Approved notification templates cannot overlap for an event and channel.');DB::table('hr_notification_template_versions')->where('id',$id)->update(['status'=>'approved','approved_by'=>$r->user()->id,'approved_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>['id'=>$id,'status'=>'approved']]);});}
public function rejectTemplate(Request$r,string$id):JsonResponse{$a=$this->actor($r);$d=$r->validate(['reason'=>['required','string','max:3000']]);return DB::transaction(function()use($r,$a,$d,$id){$t=DB::table('hr_notification_template_versions')->where('id',$id)->where('company_id',$a->company_id)->lockForUpdate()->first();abort_unless($t,404);abort_unless($t->status==='pending_approval',409);abort_if($t->created_by===$r->user()->id,409,'Template creator cannot reject the same version.');DB::table('hr_notification_template_versions')->where('id',$id)->update(['status'=>'rejected','approved_by'=>$r->user()->id,'approved_at'=>now(),'decision_reason'=>$d['reason'],'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>['id'=>$id,'status'=>'rejected']]);});}
public function preferences(Request$r):JsonResponse{$a=$this->actor($r);return response()->json(['status'=>'success','data'=>DB::table('hr_notification_preferences')->where('staff_id',$a->id)->where('company_id',$a->company_id)->select(['event_type','channel','enabled','updated_at'])->get()]);}
public function savePreference(Request$r):JsonResponse{$a=$this->actor($r);$d=$r->validate(['event_type'=>['required','string','max:100'],'channel'=>['required',Rule::in(['in_app','email','sms'])],'enabled'=>['required','boolean']]);if(!$d['enabled'])abort_if(DB::table('hr_notification_template_versions')->where('company_id',$a->company_id)->where('event_type',$d['event_type'])->where('channel',$d['channel'])->where('status','approved')->where('mandatory',true)->whereDate('effective_from','<=',now())->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',now()))->exists(),422,'Mandatory HR notifications cannot be disabled.');$key=['staff_id'=>$a->id,'event_type'=>$d['event_type'],'channel'=>$d['channel']];DB::transaction(function()use($key,$a,$d,$r){$existing=DB::table('hr_notification_preferences')->where($key)->lockForUpdate()->first();if($existing)abort_unless((string)$existing->company_id===(string)$a->company_id,409,'Notification preference company does not match the selected Staff.');$values=['enabled'=>$d['enabled'],'updated_by'=>$r->user()->id,'updated_at'=>now()];if(!$existing){$inserted=DB::table('hr_notification_preferences')->insertOrIgnore($key+$values+['id'=>(string)Str::uuid(),'company_id'=>$a->company_id,'created_at'=>now()]);if($inserted===1)return;$existing=DB::table('hr_notification_preferences')->where($key)->lockForUpdate()->first();abort_unless($existing&&(string)$existing->company_id===(string)$a->company_id,409,'Notification preference company does not match the selected Staff.');}DB::table('hr_notification_preferences')->where($key)->where('company_id',$a->company_id)->update($values);});return response()->json(['status'=>'success']);}
public function outbox(Request $request): JsonResponse
{
    $actor = $this->actor($request);
    $rows = DB::table('hr_notification_outbox as outbox')
        ->join('staff', fn ($join) => $join->on('staff.id', '=', 'outbox.recipient_staff_id')
            ->on('staff.company_id', '=', 'outbox.company_id')
            ->on('staff.user_id', '=', 'outbox.recipient_user_id'))
        ->leftJoin('users', 'users.id', '=', 'staff.user_id')
        ->where('outbox.company_id', $actor->company_id)
        ->select([
            'outbox.id', 'outbox.event_type', 'outbox.channel', 'outbox.source_type',
            'outbox.payload_checksum', 'outbox.status', 'outbox.attempt_count',
            'outbox.available_at', 'outbox.accepted_at', 'outbox.delivered_at',
            'outbox.external_reference', 'staff.code as recipient_code',
            'users.first_name as recipient_first_name', 'users.last_name as recipient_last_name',
        ])
        ->latest('outbox.created_at')->paginate($request->integer('per_page', 50))
        ->through(function ($row) {
            $row->recipient_label = trim(($row->recipient_first_name ?? '').' '.($row->recipient_last_name ?? ''))
                ?: $row->recipient_code;
            unset($row->recipient_code, $row->recipient_first_name, $row->recipient_last_name);

            return $row;
        });

    return response()->json(['status' => 'success', 'data' => $rows]);
}
public function deliveryQueue(Request $request): JsonResponse
{
    
    $actor = $this->actor($request);
    $maxAttempts = max(1, (int) config('hr.notification_max_attempts', 5));
    $query = DB::table('hr_notification_outbox')->where('company_id', $actor->company_id);
    $rows = $query
        ->where(function ($available) use ($maxAttempts): void {
            $available->where(function ($ready) use ($maxAttempts): void {
                $ready->where('attempt_count', '<', $maxAttempts)
                    ->whereIn('status', ['pending_delivery', 'retryable'])
                    ->where('available_at', '<=', now());
            })->orWhere(function ($expired): void {
                $expired->where('status', 'delivering')
                    ->where(fn ($lease) => $lease->whereNull('leased_until')->orWhere('leased_until', '<=', now()));
            });
        })
        ->select(['id', 'channel', 'event_type', 'payload_checksum', 'status', 'attempt_count', 'available_at', 'leased_until'])
        ->orderBy('available_at')
        ->paginate(min(100, max(1, $request->integer('per_page', 50))));

    return response()->json(['status' => 'success', 'data' => $rows]);
}
public function receiptQueue(Request$r):JsonResponse{$a=$this->actor($r);$query=DB::table('hr_notification_outbox')->where('company_id',$a->company_id);$this->scopeRecipientCompanyLink($query);$rows=$query->where('status','accepted_pending_receipt')->select(['id','channel','event_type','payload_checksum','attempt_count','accepted_at','external_reference'])->orderBy('accepted_at')->paginate(min(100,max(1,$r->integer('per_page',50))));return response()->json(['status'=>'success','data'=>$rows]);}
public function claimDelivery(Request $request, string $id, HrNotificationService $notifications): JsonResponse
{
    
    $actor = $this->actor($request);

    return DB::transaction(function () use ($request, $actor, $id, $notifications) {
        $outbox = DB::table('hr_notification_outbox')->where('id', $id)
            ->where('company_id', $actor->company_id)->lockForUpdate()->first();
        abort_unless($outbox, 404);
        if (! $notifications->companyIsActive($outbox->company_id)) {
            return $this->failDeliveryForRecipient($request, $outbox, 'Notification company is no longer active.');
        }
        if (! $notifications->recipientMatchesCompany($outbox->company_id, $outbox->recipient_staff_id, $outbox->recipient_user_id)) {
            return $this->failDeliveryForRecipient($request, $outbox, 'Notification recipient does not match its company.');
        }

        $claimable = in_array($outbox->status, ['pending_delivery', 'retryable'], true)
            && now()->greaterThanOrEqualTo($outbox->available_at);
        $expired = $outbox->status === 'delivering'
            && (! $outbox->leased_until || now()->greaterThanOrEqualTo($outbox->leased_until));
        abort_unless($claimable || $expired, 409, 'Notification is not available for delivery.');

        if (! $notifications->recipientIsActive($outbox->company_id, $outbox->recipient_staff_id, $outbox->recipient_user_id)) {
            return $this->failDeliveryForRecipient($request, $outbox, 'Notification recipient is no longer active in the notification company.');
        }

        $maxAttempts = max(1, (int) config('hr.notification_max_attempts', 5));
        if ($outbox->attempt_count >= $maxAttempts) {
            DB::table('hr_notification_outbox')->where('id', $id)->update([
                'status' => 'failed', 'lease_token' => null, 'leased_until' => null,
                'last_error' => 'Notification delivery lease expired at the configured attempt limit.',
                'updated_at' => now(),
            ]);
            DB::table('hr_notification_delivery_events')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'outbox_id' => $id, 'event_type' => 'failed',
                'attempt_number' => $outbox->attempt_count, 'payload_checksum' => $outbox->payload_checksum,
                'message' => 'Notification delivery lease expired at the configured attempt limit.',
                'actor_user_id' => $request->user()->id, 'occurred_at' => now(),
            ]);

            return response()->json(['status' => 'error', 'message' => 'Notification delivery attempt limit has been reached.'], 409);
        }

        $payload = decrypt($outbox->encrypted_rendered_payload);
        $encodedPayload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : false;
        abort_unless(
            $encodedPayload !== false && hash_equals($outbox->payload_checksum, hash('sha256', $encodedPayload)),
            409,
            'Queued HR notification integrity check failed.'
        );

        $attempt = $outbox->attempt_count + 1;
        $leaseToken = hash('sha256', Str::random(64).$outbox->id.now()->format('U.u'));
        $leasedUntil = now()->addSeconds(max(30, (int) config('hr.notification_lease_seconds', 120)));
        DB::table('hr_notification_outbox')->where('id', $id)->update([
            'status' => 'delivering', 'attempt_count' => $attempt,
            'lease_token' => $leaseToken, 'leased_until' => $leasedUntil, 'updated_at' => now(),
        ]);
        DB::table('hr_notification_delivery_events')->insert([
            'id' => (string) Str::uuid(), 'outbox_id' => $id, 'event_type' => $expired ? 'reclaimed' : 'claimed',
            'attempt_number' => $attempt, 'payload_checksum' => $outbox->payload_checksum,
            'message' => $expired ? 'Expired adapter lease was reclaimed.' : null,
            'actor_user_id' => $request->user()->id, 'occurred_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'data' => [
            'id' => $outbox->id, 'channel' => $outbox->channel, 'event_type' => $outbox->event_type,
            'payload' => $payload, 'payload_checksum' => $outbox->payload_checksum,
            'attempt_count' => $attempt, 'lease_token' => $leaseToken, 'leased_until' => $leasedUntil,
        ]]);
    });
}
public function acknowledge(Request $r, string $id, HrNotificationService $notifications): JsonResponse
{
    
    $actor = $this->actor($r);
    $data = $r->validate([
        'outcome' => ['required', Rule::in(['accepted', 'failed'])],
        'payload_checksum' => ['required', 'string', 'size:64'],
        'lease_token' => ['required', 'string', 'size:64'],
        'external_reference' => ['nullable', 'required_if:outcome,accepted', 'string', 'max:160'],
        'message' => ['required_if:outcome,failed', 'string', 'max:4000'],
    ]);
    $data['message'] = ObservabilitySanitizer::text($data['message'] ?? null);

    return DB::transaction(function () use ($r, $actor, $id, $data, $notifications) {
        $outbox = DB::table('hr_notification_outbox')->where('id', $id)
            ->where('company_id', $actor->company_id)->lockForUpdate()->first();
        abort_unless($outbox, 404);
        abort_unless($notifications->recipientMatchesCompany($outbox->company_id, $outbox->recipient_staff_id, $outbox->recipient_user_id), 409, 'Notification recipient does not match its company.');

        $leaseHash = hash('sha256', $data['lease_token']);
        $prior = DB::table('hr_notification_delivery_events')->where('outbox_id', $id)
            ->where('lease_token_hash', $leaseHash)->first();
        if ($prior) {
            $this->assertAcknowledgementReplay($prior, $r, $data);

            return $this->outboxResponse($outbox);
        }

        abort_unless($outbox->status === 'delivering' && $outbox->leased_until && now()->lessThan($outbox->leased_until), 409, 'Notification delivery lease is not active.');
        abort_unless(hash_equals((string) $outbox->lease_token, $data['lease_token']), 409, 'Notification delivery lease mismatch.');
        abort_unless(hash_equals($outbox->payload_checksum, $data['payload_checksum']), 409, 'Notification payload checksum mismatch.');

        $accepted = $data['outcome'] === 'accepted';
        $terminal = !$accepted && $outbox->attempt_count >= max(1, (int) config('hr.notification_max_attempts', 5));
        $status = $accepted ? 'accepted_pending_receipt' : ($terminal ? 'failed' : 'retryable');
        $eventType = $accepted ? 'provider_accepted' : ($terminal ? 'failed' : 'retry_scheduled');
        $delay = min(max(1, (int) config('hr.notification_retry_max_minutes', 60)), 2 ** max(0, $outbox->attempt_count - 1));
        DB::table('hr_notification_outbox')->where('id', $id)->update([
            'status' => $status,
            'available_at' => $accepted || $terminal ? $outbox->available_at : now()->addMinutes($delay),
            'lease_token' => null, 'leased_until' => null, 'accepted_at' => $accepted ? now() : null,
            'external_reference' => $data['external_reference'] ?? null,
            'last_error' => $accepted ? null : ($data['message'] ?? 'Adapter handoff failed.'), 'updated_at' => now(),
        ]);
        DB::table('hr_notification_delivery_events')->insert([
            'id' => (string) Str::uuid(), 'outbox_id' => $id, 'event_type' => $eventType,
            'attempt_number' => $outbox->attempt_count, 'payload_checksum' => $outbox->payload_checksum,
            'lease_token_hash' => $leaseHash, 'external_reference' => $data['external_reference'] ?? null,
            'message' => $data['message'] ?? null, 'actor_user_id' => $r->user()->id, 'occurred_at' => now(),
        ]);

        $updated = DB::table('hr_notification_outbox')->where('id', $id)
            ->where('company_id', $actor->company_id)->first();

        return $this->outboxResponse($updated);
    });
}
private function assertAcknowledgementReplay(object $prior, Request $request, array $data): void
{
    $expectedTypes = $data['outcome'] === 'accepted'
        ? ['provider_accepted']
        : ['failed', 'retry_scheduled'];
    abort_unless(
        in_array($prior->event_type, $expectedTypes, true)
            && hash_equals($prior->payload_checksum, $data['payload_checksum'])
            && $prior->external_reference === ($data['external_reference'] ?? null)
            && ($prior->message ?? null) === ($data['message'] ?? null)
            && $prior->actor_user_id === $request->user()->id,
        409,
        'Notification acknowledgement does not match the completed lease.'
    );
}
private function outboxResponse(object $outbox): JsonResponse
{
    return response()->json(['status' => 'success', 'data' => [
        'id' => $outbox->id, 'status' => $outbox->status, 'attempt_count' => $outbox->attempt_count,
        'available_at' => $outbox->available_at, 'accepted_at' => $outbox->accepted_at,
        'external_reference' => $outbox->external_reference,
        'last_error' => ObservabilitySanitizer::text($outbox->last_error),
        'updated_at' => $outbox->updated_at,
    ]]);
}
public function recordReceipt(Request $r, string $id, HrNotificationService $notifications): JsonResponse
{
    
    $actor = $this->actor($r);
    $data = $r->validate([
        'outcome' => ['required', Rule::in(['delivered', 'failed'])],
        'payload_checksum' => ['required', 'string', 'size:64'],
        'external_reference' => ['required', 'string', 'max:160'],
        'provider_event_id' => ['required', 'string', 'max:200'],
        'message' => ['nullable', 'required_if:outcome,failed', 'string', 'max:4000'],
    ]);
    $data['message'] = ObservabilitySanitizer::text($data['message'] ?? null);

    return DB::transaction(function () use ($r, $actor, $id, $data, $notifications) {
        $outbox = DB::table('hr_notification_outbox')->where('id', $id)
            ->where('company_id', $actor->company_id)->lockForUpdate()->first();
        abort_unless($outbox, 404);
        abort_unless($notifications->recipientMatchesCompany($outbox->company_id, $outbox->recipient_staff_id, $outbox->recipient_user_id), 409, 'Notification recipient does not match its company.');

        $prior = DB::table('hr_notification_delivery_events')
            ->where('provider_event_id', $data['provider_event_id'])->first();
        if ($prior) {
            $this->assertReceiptReplay($prior, $id, $data);

            return $this->outboxResponse($outbox);
        }

        abort_unless($outbox->status === 'accepted_pending_receipt', 409, 'Notification is not awaiting a provider receipt.');
        abort_unless(hash_equals($outbox->payload_checksum, $data['payload_checksum']), 409, 'Notification payload checksum mismatch.');
        abort_unless(hash_equals((string) $outbox->external_reference, $data['external_reference']), 409, 'Provider reference does not match the accepted handoff.');

        $delivered = $data['outcome'] === 'delivered';
        $event = [
            'id' => (string) Str::uuid(), 'outbox_id' => $id, 'event_type' => $data['outcome'],
            'attempt_number' => $outbox->attempt_count, 'payload_checksum' => $outbox->payload_checksum,
            'external_reference' => $outbox->external_reference, 'provider_event_id' => $data['provider_event_id'],
            'message' => $data['message'] ?? null, 'actor_user_id' => $r->user()->id, 'occurred_at' => now(),
        ];
        if (DB::table('hr_notification_delivery_events')->insertOrIgnore([$event]) !== 1) {
            $prior = DB::table('hr_notification_delivery_events')
                ->where('provider_event_id', $data['provider_event_id'])->lockForUpdate()->first();
            $this->assertReceiptReplay($prior, $id, $data);

            return $this->outboxResponse($outbox);
        }

        DB::table('hr_notification_outbox')->where('id', $id)->update([
            'status' => $data['outcome'], 'delivered_at' => $delivered ? now() : null,
            'last_error' => $delivered ? null : ($data['message'] ?? 'Provider reported delivery failure.'),
            'updated_at' => now(),
        ]);

        return $this->outboxResponse(DB::table('hr_notification_outbox')
            ->where('id', $id)->where('company_id', $actor->company_id)->first());
    });
}
private function assertReceiptReplay(?object $prior, string $id, array $data): void
{
    abort_unless(
        $prior
            && $prior->outbox_id === $id
            && $prior->event_type === $data['outcome']
            && hash_equals($prior->payload_checksum, $data['payload_checksum'])
            && $prior->external_reference === $data['external_reference']
            && ($prior->message ?? null) === ($data['message'] ?? null),
        409,
        'Provider receipt identifier was already used for a different delivery fact.'
    );
}
private function scopeRecipientCompanyLink($query): void
{
    $query->whereExists(fn ($recipient) => $recipient->selectRaw('1')->from('staff as recipient_staff')
        ->join('users as recipient_user', 'recipient_user.id', '=', 'recipient_staff.user_id')
        ->whereColumn('recipient_staff.id', 'hr_notification_outbox.recipient_staff_id')
        ->whereColumn('recipient_staff.company_id', 'hr_notification_outbox.company_id')
        ->whereColumn('recipient_staff.user_id', 'hr_notification_outbox.recipient_user_id'));
}
private function failDeliveryForRecipient(Request $request, object $outbox, string $message): JsonResponse
{
    DB::table('hr_notification_outbox')->where('id', $outbox->id)->update([
        'status' => 'failed', 'lease_token' => null, 'leased_until' => null,
        'last_error' => $message, 'updated_at' => now(),
    ]);
    DB::table('hr_notification_delivery_events')->insertOrIgnore([
        'id' => (string) Str::uuid(), 'outbox_id' => $outbox->id, 'event_type' => 'failed',
        'attempt_number' => $outbox->attempt_count, 'payload_checksum' => $outbox->payload_checksum,
        'message' => $message, 'actor_user_id' => $request->user()->id, 'occurred_at' => now(),
    ]);

    return response()->json(['status' => 'error', 'message' => $message], 409);
}
private function actor(Request$r):Staff{return app(StaffAccessService::class)->currentActorStaff($r->user());}}
