<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EngagementController extends Controller
{
    public function announcements(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = DB::table('hr_announcements')->where('company_id', $actor->company_id)
            ->where('status', 'published')->where('publish_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
        $this->audience($query, $actor);

        return response()->json(['status' => 'success', 'data' => $query
            ->select(['id', 'title', 'body', 'priority', 'acknowledgement_required', 'publish_at', 'expires_at', 'content_checksum'])
            ->latest('publish_at')->paginate($request->integer('per_page', 50))]);
    }

    public function storeAnnouncement(Request $request): JsonResponse
    {
        $this->enabled();
        $actor = $this->actor($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:50000'],
            'audience' => ['required', 'array'], 'audience.all' => ['required', 'boolean'], 'audience.staff_ids' => ['present', 'array'], 'audience.staff_ids.*' => ['uuid'], 'audience.staff_types' => ['present', 'array'], 'audience.staff_types.*' => ['string', 'max:100'], 'audience.organization_unit_ids' => ['present', 'array'], 'audience.organization_unit_ids.*' => ['uuid'], 'audience.location_codes' => ['present', 'array'], 'audience.location_codes.*' => ['string', 'max:100'], 'priority' => ['required', Rule::in(['normal', 'high', 'emergency'])],
            'acknowledgement_required' => ['required', 'boolean'], 'publish_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'], 'source_timezone' => ['required', 'timezone'],
        ]);
        $this->assertAudience($data['audience']);
        $publishAt = \Carbon\CarbonImmutable::parse($data['publish_at'], $data['source_timezone'])->utc(); $expiresAt = isset($data['expires_at']) ? \Carbon\CarbonImmutable::parse($data['expires_at'], $data['source_timezone'])->utc() : null; abort_if($expiresAt && $expiresAt->lessThanOrEqualTo($publishAt), 422, 'Expiry must be after publication.'); $snapshot = $data; $snapshot['publish_at'] = $publishAt->toIso8601String(); $snapshot['expires_at'] = $expiresAt?->toIso8601String();
        $id = (string) Str::uuid();
        DB::table('hr_announcements')->insert([
            'id' => $id, 'company_id' => $actor->company_id, 'title' => $data['title'], 'body' => $data['body'],
            'audience' => json_encode($data['audience'], JSON_THROW_ON_ERROR), 'priority' => $data['priority'],
            'acknowledgement_required' => $data['acknowledgement_required'], 'publish_at' => $publishAt,
            'expires_at' => $expiresAt, 'source_timezone' => $data['source_timezone'], 'status' => 'pending_approval', 'created_by' => $request->user()->id,
            'content_checksum' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'data' => DB::table('hr_announcements')->find($id)], 201);
    }

    public function approveAnnouncement(Request $request, string $id): JsonResponse
    {
        $this->enabled();
        return DB::transaction(function () use ($request, $id) {
            $row = DB::table('hr_announcements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404); $this->company($request, $row->company_id);
            abort_unless($row->status === 'pending_approval', 409);
            abort_if($row->created_by === $request->user()->id, 409, 'Announcement creator cannot approve the same content.');
            DB::table('hr_announcements')->where('id', $id)->update(['status' => 'published', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_announcements')->find($id)]);
        });
    }

    public function rejectAnnouncement(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        return DB::transaction(function () use ($request, $id, $data) {
            $row = DB::table('hr_announcements')->where('id', $id)->lockForUpdate()->first(); abort_unless($row, 404); $this->company($request, $row->company_id);
            abort_unless($row->status === 'pending_approval', 409); abort_if($row->created_by === $request->user()->id, 409, 'Announcement creator cannot reject the same content.');
            DB::table('hr_announcements')->where('id', $id)->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $data['reason'], 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_announcements')->find($id)]);
        });
    }

    public function acknowledgeAnnouncement(Request $request, string $id): JsonResponse
    {
        $this->enabled();
        $actor = $this->actor($request);
        $query = DB::table('hr_announcements')->where('id', $id)->where('company_id', $actor->company_id)->where('status', 'published');
        $this->audience($query, $actor);
        $row = $query->first(); abort_unless($row, 404);
        DB::table('hr_announcement_acknowledgements')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'announcement_id' => $id, 'staff_id' => $actor->id,
            'content_checksum' => $row->content_checksum, 'acknowledged_at' => now(),
        ]);
        return response()->json(['status' => 'success']);
    }

    public function storeSurvey(Request $request): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'], 'version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'], 'survey_type' => ['required', Rule::in(['pulse', 'engagement', 'enps', 'custom'])],
            'anonymous' => ['required', 'boolean'], 'minimum_report_group' => ['required', 'integer', 'min:5', 'max:1000'],
            'audience' => ['required', 'array'], 'audience.all' => ['required', 'boolean'], 'audience.staff_ids' => ['present', 'array'], 'audience.staff_ids.*' => ['uuid'], 'audience.staff_types' => ['present', 'array'], 'audience.staff_types.*' => ['string', 'max:100'], 'audience.organization_unit_ids' => ['present', 'array'], 'audience.organization_unit_ids.*' => ['uuid'], 'audience.location_codes' => ['present', 'array'], 'audience.location_codes.*' => ['string', 'max:100'], 'questions' => ['required', 'array', 'min:1'],
            'questions.*.id' => ['required', 'string', 'max:80'], 'questions.*.label' => ['required', 'string', 'max:1000'], 'questions.*.type' => ['required', Rule::in(['scale', 'single_choice', 'multi_choice', 'text'])], 'questions.*.min' => ['nullable', 'numeric'], 'questions.*.max' => ['nullable', 'numeric'], 'questions.*.options' => ['nullable', 'array'], 'questions.*.options.*.value' => ['required_with:questions.*.options', 'string', 'max:500'], 'questions.*.options.*.label' => ['required_with:questions.*.options', 'string', 'max:500'],
            'allowed_reporting_dimensions' => ['required', 'array'],
            'allowed_reporting_dimensions.*' => ['required', Rule::in(['organization_unit', 'location', 'staff_type', 'tenure_band'])],
            'opens_at' => ['required', 'date'], 'closes_at' => ['required', 'date', 'after:opens_at'], 'source_timezone' => ['required', 'timezone'],
        ]);
        $this->assertAudience($data['audience']); $this->assertQuestions($data['questions']); abort_if(count($data['allowed_reporting_dimensions']) !== count(array_unique($data['allowed_reporting_dimensions'])), 422, 'Reporting dimensions must be unique.');
        abort_if(collect($data['questions'])->pluck('id')->duplicates()->isNotEmpty(), 422, 'Question identifiers must be unique.');
        $opensAt = \Carbon\CarbonImmutable::parse($data['opens_at'], $data['source_timezone'])->utc(); $closesAt = \Carbon\CarbonImmutable::parse($data['closes_at'], $data['source_timezone'])->utc(); abort_unless($closesAt->greaterThan($opensAt), 422, 'Survey close must be after opening.'); $data['opens_at'] = $opensAt->toIso8601String(); $data['closes_at'] = $closesAt->toIso8601String();
        $checksum = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        foreach (['audience', 'questions', 'allowed_reporting_dimensions'] as $key) $data[$key] = json_encode($data[$key], JSON_THROW_ON_ERROR);
        DB::table('hr_survey_versions')->insert($data + ['id' => $id, 'company_id' => $actor->company_id, 'status' => 'pending_approval', 'created_by' => $request->user()->id, 'survey_checksum' => $checksum, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_survey_versions')->find($id)], 201);
    }

    public function surveys(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = DB::table('hr_survey_versions')->where('company_id', $actor->company_id)->where('status', 'approved')->where('opens_at', '<=', now())->where('closes_at', '>=', now());
        $this->audience($query, $actor);
        $page = $query->select(['id', 'code', 'version', 'title', 'survey_type', 'anonymous', 'questions', 'opens_at', 'closes_at', 'survey_checksum'])->latest('opens_at')->paginate($request->integer('per_page', 50));
        $page->setCollection($page->getCollection()->map(function ($survey) use ($actor) {
            $survey->questions = json_decode($survey->questions, true) ?: [];
            $survey->responded = DB::table('hr_survey_participations')->where('survey_version_id', $survey->id)->where('staff_id', $actor->id)->exists();
            return $survey;
        }));
        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function approveSurvey(Request $request, string $id): JsonResponse
    {
        $this->enabled();
        return DB::transaction(function () use ($request, $id) {
            $row = DB::table('hr_survey_versions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404); $this->company($request, $row->company_id);
            abort_unless($row->status === 'pending_approval', 409);
            abort_if($row->created_by === $request->user()->id, 409, 'Survey creator cannot approve the same version.');
            DB::table('hr_survey_versions')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_survey_versions')->find($id)]);
        });
    }

    public function rejectSurvey(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        return DB::transaction(function () use ($request, $id, $data) {
            $row = DB::table('hr_survey_versions')->where('id', $id)->lockForUpdate()->first(); abort_unless($row, 404); $this->company($request, $row->company_id);
            abort_unless($row->status === 'pending_approval', 409); abort_if($row->created_by === $request->user()->id, 409, 'Survey creator cannot reject the same version.');
            DB::table('hr_survey_versions')->where('id', $id)->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $data['reason'], 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_survey_versions')->find($id)]);
        });
    }

    public function respondSurvey(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['responses' => ['required', 'array']]);
        $query = DB::table('hr_survey_versions')->where('id', $id)->where('company_id', $actor->company_id)
            ->where('status', 'approved')->where('opens_at', '<=', now())->where('closes_at', '>=', now());
        $this->audience($query, $actor);
        $survey = $query->first(); abort_unless($survey, 404);
        abort_if(DB::table('hr_survey_participations')->where('survey_version_id', $id)->where('staff_id', $actor->id)->exists(), 409, 'Survey response already submitted.');
        $allowed = json_decode($survey->allowed_reporting_dimensions, true) ?: [];
        $dimensions = $this->reportingDimensions($actor, $allowed);
        $questions = collect(json_decode($survey->questions, true) ?: [])->pluck('id')->filter()->all();
        abort_if(array_diff(array_keys($data['responses']), $questions), 422, 'Response contains an unknown question.');

        return DB::transaction(function () use ($actor, $survey, $data, $dimensions) {
            DB::table('hr_survey_participations')->insert(['id' => (string) Str::uuid(), 'survey_version_id' => $survey->id, 'staff_id' => $actor->id, 'participated_on' => now()->toDateString()]);
            $payload = ['survey_checksum' => $survey->survey_checksum, 'responses' => $data['responses'], 'dimensions' => $dimensions];
            DB::table('hr_survey_responses')->insert([
                'id' => (string) Str::uuid(), 'survey_version_id' => $survey->id,
                'respondent_staff_id' => $survey->anonymous ? null : $actor->id,
                'response_payload' => json_encode($data['responses'], JSON_THROW_ON_ERROR),
                'reporting_dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
                'response_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'submitted_at' => now(),
            ]);
            return response()->json(['status' => 'success', 'anonymous' => (bool) $survey->anonymous], 201);
        });
    }

    public function surveyResults(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $survey = DB::table('hr_survey_versions')->where('id', $id)->where('company_id', $actor->company_id)->first();
        abort_unless($survey, 404);
        $data = $request->validate(['dimension' => ['nullable', Rule::in(['organization_unit', 'location', 'staff_type', 'tenure_band'])]]);
        $dimension = $data['dimension'] ?? null;
        if ($dimension) abort_unless(in_array($dimension, json_decode($survey->allowed_reporting_dimensions, true) ?: [], true), 422);
        $responses = DB::table('hr_survey_responses')->where('survey_version_id', $id)->get();
        if (!$dimension) {
            abort_if($responses->count() < $survey->minimum_report_group, 409, 'Minimum anonymous reporting threshold is not met.');
            return response()->json(['status' => 'success', 'data' => ['response_count' => $responses->count(), 'minimum_group_size' => $survey->minimum_report_group, 'aggregates' => $this->aggregate($responses, $survey)]]);
        }
        $groups = $responses->groupBy(fn ($row) => (string) ((json_decode($row->reporting_dimensions, true) ?: [])[$dimension] ?? 'unspecified'))
            ->map(fn ($rows) => $rows->count() < $survey->minimum_report_group
                ? ['suppressed' => true, 'response_count' => null, 'aggregates' => null]
                : ['suppressed' => false, 'response_count' => $rows->count(), 'aggregates' => $this->aggregate($rows, $survey)]);
        return response()->json(['status' => 'success', 'data' => ['dimension' => $dimension, 'minimum_group_size' => $survey->minimum_report_group, 'groups' => $groups]]);
    }

    public function nominateRecognition(Request $request): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate([
            'nominee_staff_id' => ['required', 'uuid'], 'achievement_id' => ['nullable', 'uuid'], 'category' => ['required', 'string', 'max:60'],
            'citation' => ['required', 'string', 'max:10000'], 'evidence' => ['nullable', 'array', 'max:20'], 'evidence.*' => ['required', 'string', 'max:500'], 'visibility' => ['required', Rule::in(['private', 'team', 'company'])],
            'reward_proposal' => ['nullable', 'array'], 'reward_proposal.kind' => ['required_with:reward_proposal', Rule::in(['non_monetary', 'monetary'])], 'reward_proposal.description' => ['required_with:reward_proposal', 'string', 'max:2000'], 'reward_proposal.amount' => ['nullable', 'required_if:reward_proposal.kind,monetary', 'numeric', 'min:0.01'], 'reward_proposal.currency' => ['nullable', 'required_if:reward_proposal.kind,monetary', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $nominee = Staff::query()->whereKey($data['nominee_staff_id'])->where('company_id', $actor->company_id)->whereNull('employment_ended_at')->firstOrFail(); abort_if($nominee->id === $actor->id, 422, 'Self-nomination is not permitted.');
        if (isset($data['achievement_id'])) abort_unless(DB::table('hr_achievements')->where('id', $data['achievement_id'])->where('staff_id', $nominee->id)->where('status', 'verified')->exists(), 422, 'Only a verified achievement may be linked.');
        $id = (string) Str::uuid(); $evidence = array_values(array_unique($data['evidence'] ?? [])); $reward = $data['reward_proposal'] ?? null; if ($reward && ($reward['kind'] ?? null) === 'monetary') $reward['currency'] = strtoupper($reward['currency']); $snapshot = ['nominee_staff_id' => $nominee->id, 'nominator_staff_id' => $actor->id, 'achievement_id' => $data['achievement_id'] ?? null, 'category' => $data['category'], 'citation' => $data['citation'], 'evidence' => $evidence, 'visibility' => $data['visibility'], 'reward_proposal' => $reward]; $checksum = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        DB::table('hr_recognition_nominations')->insert([
            'id' => $id, 'company_id' => $actor->company_id, 'nominee_staff_id' => $nominee->id, 'nominator_staff_id' => $actor->id,
            'achievement_id' => $data['achievement_id'] ?? null, 'category' => $data['category'], 'citation' => $data['citation'],
            'evidence' => $evidence ? json_encode($evidence, JSON_THROW_ON_ERROR) : null, 'visibility' => $data['visibility'],
            'status' => 'pending_approval', 'reward_proposal' => $reward ? json_encode($reward, JSON_THROW_ON_ERROR) : null, 'nomination_checksum' => $checksum,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_recognition_nominations')->find($id)], 201);
    }

    public function recognitionNominees(Request $request): JsonResponse
    {
        $actor = $this->actor($request); $rows = DB::table('staff as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.company_id', $actor->company_id)->whereNull('s.employment_ended_at')->whereNull('s.deleted_at')->where('u.is_active', true)->select(['s.id', 's.code', 'u.first_name', 'u.last_name'])->orderBy('u.first_name')->orderBy('u.last_name')->get(); return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function recognitionNominations(Request $request): JsonResponse
    {
        $actor = $this->actor($request); $assignment = DB::table('hr_employment_assignments')->where('staff_id', $actor->id)->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))->latest('effective_from')->first(); $query = DB::table('hr_recognition_nominations as n')->leftJoin('staff as nominee', 'nominee.id', '=', 'n.nominee_staff_id')->leftJoin('users as nominee_user', 'nominee_user.id', '=', 'nominee.user_id')->where('n.company_id', $actor->company_id)->where(function ($scope) use ($actor, $assignment) { $scope->where('n.nominator_staff_id', $actor->id)->orWhere(function ($approved) use ($actor, $assignment) { $approved->where('n.status', 'approved')->where(function ($visible) use ($actor, $assignment) { $visible->where('n.nominee_staff_id', $actor->id)->orWhere('n.visibility', 'company'); if ($assignment?->organization_unit_id) $visible->orWhere(function ($team) use ($assignment) { $team->where('n.visibility', 'team')->whereExists(fn ($q) => $q->selectRaw('1')->from('hr_employment_assignments as nominee_assignment')->whereColumn('nominee_assignment.staff_id', 'n.nominee_staff_id')->where('nominee_assignment.organization_unit_id', $assignment->organization_unit_id)->whereDate('nominee_assignment.effective_from', '<=', now())->where(fn ($dates) => $dates->whereNull('nominee_assignment.effective_until')->orWhereDate('nominee_assignment.effective_until', '>=', now()))); }); }); }); }); $page = $query->select(['n.id', 'n.nominee_staff_id', 'nominee.code as nominee_code', 'nominee_user.first_name as nominee_first_name', 'nominee_user.last_name as nominee_last_name', 'n.nominator_staff_id', 'n.category', 'n.citation', 'n.visibility', 'n.status', 'n.nomination_checksum', 'n.approved_at', 'n.decision_reason', 'n.created_at'])->latest('n.created_at')->paginate($request->integer('per_page', 50)); return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function decideRecognition(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected', 'revoked'])], 'reason' => ['required', 'string', 'max:2000']]);
        return DB::transaction(function () use ($request, $id, $data) {
            $row = DB::table('hr_recognition_nominations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404); $this->company($request, $row->company_id);
            $allowed = ['pending_approval' => ['approved', 'rejected'], 'approved' => ['revoked']];
            abort_unless(in_array($data['decision'], $allowed[$row->status] ?? [], true), 409);
            $actor = $this->actor($request); abort_if(in_array($actor->id, [$row->nominator_staff_id, $row->nominee_staff_id], true) && $data['decision'] === 'approved', 409, 'Nominator and nominee cannot approve the same recognition.'); $checksum = hash('sha256', json_encode($this->recognitionPayload($row), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); abort_unless(hash_equals($row->nomination_checksum, $checksum), 409, 'Recognition nomination changed after submission.');
            DB::table('hr_recognition_nominations')->where('id', $id)->update(['status' => $data['decision'], 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $data['reason'], 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_recognition_nominations')->find($id)]);
        });
    }

    public function storeWellnessProgram(Request $request): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'], 'audience' => ['required', 'array'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at']]);
        $id = (string) Str::uuid(); $data['audience'] = json_encode($data['audience'], JSON_THROW_ON_ERROR);
        DB::table('hr_wellness_programs')->insert($data + ['id' => $id, 'company_id' => $actor->company_id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_wellness_programs')->find($id)], 201);
    }

    public function wellnessPrograms(Request $request): JsonResponse
    {
        $actor = $this->actor($request); $query = DB::table('hr_wellness_programs')->where('company_id', $actor->company_id)->where('status', 'active')->whereDate('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now())); $this->audience($query, $actor);
        return response()->json(['status' => 'success', 'data' => $query->select(['id', 'code', 'name', 'description', 'starts_at', 'ends_at'])->orderBy('name')->get()]);
    }

    public function requestWellnessReferral(Request $request): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['program_id' => ['nullable', 'uuid'], 'referral_type' => ['required', Rule::in(['self_referral', 'assistance', 'wellness', 'occupational_support'])], 'details' => ['required', 'string', 'max:10000']]);
        if (isset($data['program_id'])) {
            $query = DB::table('hr_wellness_programs')->where('id', $data['program_id'])->where('company_id', $actor->company_id)->where('status', 'active')->whereDate('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
            $this->audience($query, $actor); abort_unless($query->exists(), 422, 'The selected programme is not available to this employee.');
        }
        $id = (string) Str::uuid(); $at = now();
        DB::transaction(function () use ($id, $actor, $data, $request, $at) {
            DB::table('hr_wellness_referrals')->insert(['id' => $id, 'company_id' => $actor->company_id, 'staff_id' => $actor->id, 'program_id' => $data['program_id'] ?? null, 'referral_type' => $data['referral_type'], 'encrypted_details' => encrypt($data['details']), 'status' => 'requested', 'consent_status' => 'pending', 'requested_by' => $request->user()->id, 'created_at' => $at, 'updated_at' => $at]);
            $payload = ['referral_id' => $id, 'event_type' => 'requested', 'from' => null, 'to' => 'requested', 'actor' => $request->user()->id, 'at' => $at->toIso8601String()];
            DB::table('hr_wellness_referral_events')->insert(['id' => (string) Str::uuid(), 'referral_id' => $id, 'event_type' => 'requested', 'from_status' => null, 'to_status' => 'requested', 'encrypted_case_note' => encrypt('Confidential wellness support requested.'), 'employee_visible' => true, 'event_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'actor_user_id' => $request->user()->id, 'occurred_at' => $at]);
        });
        return response()->json(['status' => 'success', 'data' => DB::table('hr_wellness_referrals')->select(['id', 'staff_id', 'program_id', 'referral_type', 'status', 'created_at'])->find($id)], 201);
    }

    public function wellnessReferrals(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = DB::table('hr_wellness_referrals')->where('company_id', $actor->company_id);
        if (!$request->user()->can('hr.wellness.case.manage')) $query->where('staff_id', $actor->id);
        return response()->json(['status' => 'success', 'data' => $query->select(['id', 'staff_id', 'program_id', 'referral_type', 'status', 'consent_status', 'case_owner_staff_id', 'closed_at', 'created_at'])->latest()->paginate($request->integer('per_page', 50))]);
    }

    public function wellnessReferral(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $row = DB::table('hr_wellness_referrals')->where('id', $id)->where('company_id', $actor->company_id)->first(); abort_unless($row, 404);
        $caseAccess = $request->user()->can('hr.wellness.case.manage');
        abort_unless($caseAccess || $row->staff_id === $actor->id, 403);
        if ($caseAccess) activity('hr-wellness-sensitive-access')->causedBy($request->user())->withProperties(['referral_id' => $id, 'company_id' => $row->company_id, 'ip' => $request->ip()])->log('restricted_wellness_case_viewed');
        $events = DB::table('hr_wellness_referral_events')->where('referral_id', $id)->orderBy('occurred_at')->get()->map(fn ($event) => [
            'event_type' => $event->event_type, 'from_status' => $event->from_status, 'to_status' => $event->to_status,
            'case_note' => $caseAccess || $event->employee_visible ? decrypt($event->encrypted_case_note) : null, 'employee_visible' => (bool) $event->employee_visible, 'event_checksum' => $event->event_checksum, 'occurred_at' => $event->occurred_at,
        ]);
        $consents = DB::table('hr_wellness_referral_consents')->where('referral_id', $id)->orderBy('version')->select(['id', 'version', 'decision', 'consent_scope', 'notice_version', 'consent_checksum', 'recorded_at'])->get()->map(fn ($c) => ['id' => $c->id, 'version' => $c->version, 'decision' => $c->decision, 'consent_scope' => json_decode($c->consent_scope, true) ?: [], 'notice_version' => $c->notice_version, 'consent_checksum' => $c->consent_checksum, 'recorded_at' => $c->recorded_at]);
        $followups = DB::table('hr_wellness_followups')->where('referral_id', $id)->when(!$caseAccess, fn ($q) => $q->where('employee_visible', true))->orderBy('due_at')->get()->map(fn ($f) => ['id' => $f->id, 'due_at' => $f->due_at, 'timezone' => $f->timezone, 'owner_staff_id' => $caseAccess ? $f->owner_staff_id : null, 'purpose' => decrypt($f->encrypted_purpose), 'employee_visible' => (bool) $f->employee_visible, 'status' => $f->status, 'completion_note' => $caseAccess && $f->encrypted_completion_note ? decrypt($f->encrypted_completion_note) : null, 'completed_at' => $f->completed_at]);
        return response()->json(['status' => 'success', 'data' => [
            'referral' => collect((array) $row)->except(['encrypted_details'])->all(),
            'details' => decrypt($row->encrypted_details), 'events' => $events, 'consents' => $consents, 'followups' => $followups, 'abilities' => ['manage' => $caseAccess], 'current_actor_staff_id' => $caseAccess ? $actor->id : null, 'consent_notice_version' => config('hr.wellness_consent_notice_version'),
        ]]);
    }

    public function consentWellnessReferral(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['decision' => ['required', Rule::in(['granted', 'declined', 'withdrawn'])], 'consent_scope' => ['present', 'array'], 'consent_scope.*' => ['required', Rule::in(['internal_case_handling', 'provider_referral', 'appointment_coordination', 'follow_up'])], 'notice_version' => ['required', 'string', 'max:80']]);
        return DB::transaction(function () use ($request, $actor, $data, $id) {
            $row = DB::table('hr_wellness_referrals')->where('id', $id)->where('company_id', $actor->company_id)->where('staff_id', $actor->id)->lockForUpdate()->first(); abort_unless($row, 404); $noticeVersion = config('hr.wellness_consent_notice_version'); abort_unless($noticeVersion, 409, 'An approved wellness consent notice version must be configured.'); abort_unless(hash_equals((string) $noticeVersion, $data['notice_version']), 409, 'Consent notice version is stale or not approved.');
            $allowed = ['pending' => ['granted', 'declined'], 'granted' => ['withdrawn'], 'declined' => ['granted'], 'withdrawn' => ['granted']]; abort_unless(in_array($data['decision'], $allowed[$row->consent_status] ?? [], true), 409, 'Consent decision is not valid from the current state.');
            abort_if($data['decision'] === 'granted' && count($data['consent_scope']) === 0, 422, 'Granted consent requires at least one explicit scope.');
            $version = ((int) DB::table('hr_wellness_referral_consents')->where('referral_id', $id)->max('version')) + 1; $at = now(); $scope = array_values(array_unique($data['consent_scope']));
            $snapshot = ['referral_id' => $id, 'version' => $version, 'decision' => $data['decision'], 'consent_scope' => $scope, 'notice_version' => $data['notice_version'], 'staff_id' => $actor->id, 'recorded_at' => $at->toIso8601String()]; $checksum = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            DB::table('hr_wellness_referral_consents')->insert(['id' => (string) Str::uuid(), 'referral_id' => $id, 'version' => $version, 'decision' => $data['decision'], 'consent_scope' => json_encode($scope, JSON_THROW_ON_ERROR), 'notice_version' => $data['notice_version'], 'consent_checksum' => $checksum, 'recorded_by_staff_id' => $actor->id, 'recorded_by_user_id' => $request->user()->id, 'recorded_at' => $at]);
            $nextStatus = $data['decision'] === 'withdrawn' && $row->status === 'in_support' ? 'assigned' : $row->status; DB::table('hr_wellness_referrals')->where('id', $id)->update(['consent_status' => $data['decision'], 'status' => $nextStatus, 'updated_at' => $at]);
            $event = ['referral_id' => $id, 'event_type' => 'consent_'.$data['decision'], 'from' => $row->status, 'to' => $nextStatus, 'consent_checksum' => $checksum, 'actor' => $request->user()->id, 'at' => $at->toIso8601String()]; DB::table('hr_wellness_referral_events')->insert(['id' => (string) Str::uuid(), 'referral_id' => $id, 'event_type' => 'consent_'.$data['decision'], 'from_status' => $row->status, 'to_status' => $nextStatus, 'encrypted_case_note' => encrypt('Consent decision recorded against notice '.$data['notice_version'].'.'), 'employee_visible' => true, 'event_checksum' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'actor_user_id' => $request->user()->id, 'occurred_at' => $at]);
            return response()->json(['status' => 'success', 'data' => ['decision' => $data['decision'], 'version' => $version, 'consent_checksum' => $checksum]]);
        });
    }

    public function manageWellnessReferral(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['status' => ['required', Rule::in(['assigned', 'in_support', 'closed'])], 'case_owner_staff_id' => ['nullable', 'uuid'], 'case_note' => ['required', 'string', 'max:10000']]);
        return DB::transaction(function () use ($request, $actor, $data, $id) {
            $row = DB::table('hr_wellness_referrals')->where('id', $id)->where('company_id', $actor->company_id)->lockForUpdate()->first(); abort_unless($row, 404);
            $allowed = ['requested' => ['assigned', 'closed'], 'assigned' => ['in_support', 'closed'], 'in_support' => ['closed']]; abort_unless(in_array($data['status'], $allowed[$row->status] ?? [], true), 409, 'Wellness case transition is not valid from the current state.');
            $owner = $data['case_owner_staff_id'] ?? $row->case_owner_staff_id; abort_unless($owner || $data['status'] === 'closed', 422, 'An eligible confidential case owner is required.');
            if ($owner) { $ownerStaff = Staff::query()->with('user')->whereKey($owner)->where('company_id', $actor->company_id)->whereNull('employment_ended_at')->first(); abort_unless($ownerStaff?->user?->is_active && $ownerStaff->user->can('hr.wellness.case.manage'), 422, 'Case owner must be active and hold confidential wellness-case permission.'); }
            if ($data['status'] === 'in_support') abort_unless($row->consent_status === 'granted', 409, 'Active support requires current granted consent.');
            $at = now(); DB::table('hr_wellness_referrals')->where('id', $id)->update(['status' => $data['status'], 'case_owner_staff_id' => $owner, 'assigned_by' => $owner && $owner !== $row->case_owner_staff_id ? $request->user()->id : $row->assigned_by, 'assigned_at' => $owner && $owner !== $row->case_owner_staff_id ? $at : $row->assigned_at, 'closed_at' => $data['status'] === 'closed' ? $at : null, 'updated_at' => $at]);
            $event = ['referral_id' => $id, 'event_type' => 'case_status_changed', 'from' => $row->status, 'to' => $data['status'], 'owner_staff_id' => $owner, 'actor' => $request->user()->id, 'at' => $at->toIso8601String()]; DB::table('hr_wellness_referral_events')->insert(['id' => (string) Str::uuid(), 'referral_id' => $id, 'event_type' => 'case_status_changed', 'from_status' => $row->status, 'to_status' => $data['status'], 'encrypted_case_note' => encrypt($data['case_note']), 'employee_visible' => false, 'event_checksum' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'actor_user_id' => $request->user()->id, 'occurred_at' => $at]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_wellness_referrals')->select(['id', 'staff_id', 'program_id', 'referral_type', 'status', 'case_owner_staff_id', 'closed_at'])->find($id)]);
        });
    }

    public function scheduleWellnessFollowup(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['due_at' => ['required', 'date'], 'timezone' => ['required', 'timezone'], 'owner_staff_id' => ['nullable', 'uuid'], 'purpose' => ['required', 'string', 'max:10000'], 'employee_visible' => ['required', 'boolean']]);
        return DB::transaction(function () use ($request, $actor, $data, $id) {
            $row = DB::table('hr_wellness_referrals')->where('id', $id)->where('company_id', $actor->company_id)->lockForUpdate()->first(); abort_unless($row, 404); abort_unless($row->status === 'in_support' && $row->consent_status === 'granted', 409, 'Follow-ups require active support and current granted consent.');
            $ownerId = $data['owner_staff_id'] ?? $row->case_owner_staff_id; $owner = Staff::query()->with('user')->whereKey($ownerId)->where('company_id', $actor->company_id)->whereNull('employment_ended_at')->first(); abort_unless($owner?->user?->is_active && $owner->user->can('hr.wellness.case.manage'), 422, 'Follow-up owner must be an active confidential wellness-case handler.');
            $local = \Carbon\CarbonImmutable::parse($data['due_at'], $data['timezone']); abort_unless($local->isFuture(), 422, 'Follow-up due time must be in the future.'); $dueAt = $local->utc(); $followupId = (string) Str::uuid(); $at = now();
            DB::table('hr_wellness_followups')->insert(['id' => $followupId, 'referral_id' => $id, 'due_at' => $dueAt, 'timezone' => $data['timezone'], 'owner_staff_id' => $ownerId, 'encrypted_purpose' => encrypt($data['purpose']), 'employee_visible' => $data['employee_visible'], 'status' => 'scheduled', 'created_by' => $request->user()->id, 'created_at' => $at, 'updated_at' => $at]);
            $event = ['referral_id' => $id, 'event_type' => 'followup_scheduled', 'from' => $row->status, 'to' => $row->status, 'followup_id' => $followupId, 'due_at_utc' => $dueAt->toIso8601String(), 'source_timezone' => $data['timezone'], 'actor' => $request->user()->id, 'at' => $at->toIso8601String()]; DB::table('hr_wellness_referral_events')->insert(['id' => (string) Str::uuid(), 'referral_id' => $id, 'event_type' => 'followup_scheduled', 'from_status' => $row->status, 'to_status' => $row->status, 'encrypted_case_note' => encrypt($data['employee_visible'] ? $data['purpose'] : 'A private follow-up was scheduled.'), 'employee_visible' => $data['employee_visible'], 'event_checksum' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'actor_user_id' => $request->user()->id, 'occurred_at' => $at]);
            return response()->json(['status' => 'success', 'data' => ['id' => $followupId, 'due_at' => $dueAt, 'timezone' => $data['timezone'], 'owner_staff_id' => $ownerId, 'employee_visible' => $data['employee_visible'], 'status' => 'scheduled']], 201);
        });
    }

    public function completeWellnessFollowup(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request); $data = $request->validate(['completion_note' => ['required', 'string', 'max:10000']]);
        return DB::transaction(function () use ($request, $actor, $data, $id) {
            $followup = DB::table('hr_wellness_followups as f')->join('hr_wellness_referrals as r', 'r.id', '=', 'f.referral_id')->where('f.id', $id)->where('r.company_id', $actor->company_id)->select('f.*', 'r.status as referral_status')->lockForUpdate()->first(); abort_unless($followup, 404); abort_unless($followup->status === 'scheduled', 409); abort_unless($followup->owner_staff_id === $actor->id || $request->user()->can('hr.wellness.case.manage'), 403); $at = now();
            DB::table('hr_wellness_followups')->where('id', $id)->update(['status' => 'completed', 'encrypted_completion_note' => encrypt($data['completion_note']), 'completed_by' => $request->user()->id, 'completed_at' => $at, 'updated_at' => $at]);
            $event = ['referral_id' => $followup->referral_id, 'event_type' => 'followup_completed', 'from' => $followup->referral_status, 'to' => $followup->referral_status, 'followup_id' => $id, 'actor' => $request->user()->id, 'at' => $at->toIso8601String()]; DB::table('hr_wellness_referral_events')->insert(['id' => (string) Str::uuid(), 'referral_id' => $followup->referral_id, 'event_type' => 'followup_completed', 'from_status' => $followup->referral_status, 'to_status' => $followup->referral_status, 'encrypted_case_note' => encrypt($followup->employee_visible ? $data['completion_note'] : 'A private follow-up was completed.'), 'employee_visible' => $followup->employee_visible, 'event_checksum' => hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'actor_user_id' => $request->user()->id, 'occurred_at' => $at]);
            return response()->json(['status' => 'success', 'data' => ['id' => $id, 'status' => 'completed', 'completed_at' => $at]]);
        });
    }

    private function assertAudience(array $audience): void
    {
        $allowed = ['all', 'staff_ids', 'staff_types', 'organization_unit_ids', 'location_codes']; abort_if(array_diff(array_keys($audience), $allowed), 422, 'Audience contains an unsupported selector.'); abort_unless(array_key_exists('all', $audience) && is_bool($audience['all']), 422, 'Audience all must be boolean.');
        foreach (array_slice($allowed, 1) as $key) abort_unless(array_key_exists($key, $audience) && is_array($audience[$key]), 422, "Audience {$key} must be an array.");
        foreach (['staff_ids', 'organization_unit_ids'] as $key) foreach ($audience[$key] as $value) abort_unless(is_string($value) && Str::isUuid($value), 422, "Audience {$key} contains an invalid UUID.");
        foreach (['staff_types', 'location_codes'] as $key) foreach ($audience[$key] as $value) abort_unless(is_string($value) && trim($value) !== '' && mb_strlen($value) <= 100, 422, "Audience {$key} contains an invalid value.");
        abort_unless($audience['all'] || collect(array_slice($audience, 1))->flatten()->isNotEmpty(), 422, 'Audience must select all staff or at least one explicit group.');
    }

    private function assertQuestions(array $questions): void
    {
        foreach ($questions as $question) {
            abort_unless(isset($question['label']) && is_string($question['label']) && trim($question['label']) !== '' && mb_strlen($question['label']) <= 1000, 422, 'Every survey question requires a label.'); $type = $question['type'];
            if ($type === 'scale') { abort_unless(isset($question['min'], $question['max']) && is_numeric($question['min']) && is_numeric($question['max']) && (float) $question['max'] > (float) $question['min'], 422, 'Scale questions require numeric minimum and maximum bounds.'); }
            if (in_array($type, ['single_choice', 'multi_choice'], true)) { abort_unless(isset($question['options']) && is_array($question['options']) && count($question['options']) > 0, 422, 'Choice questions require options.'); $values = collect($question['options'])->map(fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option); abort_if($values->contains(fn ($value) => !is_string($value) || trim($value) === '' || mb_strlen($value) > 500) || $values->duplicates()->isNotEmpty(), 422, 'Choice option values must be non-empty and unique.'); }
        }
    }

    private function recognitionPayload(object $row): array
    {
        return ['nominee_staff_id' => $row->nominee_staff_id, 'nominator_staff_id' => $row->nominator_staff_id, 'achievement_id' => $row->achievement_id, 'category' => $row->category, 'citation' => $row->citation, 'evidence' => json_decode($row->evidence ?? '[]', true) ?: [], 'visibility' => $row->visibility, 'reward_proposal' => json_decode($row->reward_proposal ?? 'null', true)];
    }

    private function aggregate(Collection $rows, object $survey): array
    {
        $questionTypes = collect(json_decode($survey->questions, true) ?: [])->mapWithKeys(fn ($q) => [(string) $q['id'] => $q['type'] ?? 'text']);
        $answers = $rows->map(fn ($row) => json_decode($row->response_payload, true) ?: []);
        return $questionTypes->map(function ($type, $id) use ($answers) {
            $values = $answers->map(fn ($response) => $response[$id] ?? null)->filter(fn ($value) => $value !== null);
            if ($type === 'text') return ['type' => 'text', 'answered_count' => $values->count(), 'values_withheld' => true];
            if ($type === 'scale') {
                $numeric = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (float) $value);
                return ['type' => 'scale', 'answered_count' => $numeric->count(), 'average' => $numeric->isEmpty() ? null : round($numeric->avg(), 4), 'distribution' => $numeric->countBy(fn ($v) => (string) $v)->sortKeys()];
            }
            $choices = $type === 'multi_choice' ? $values->flatMap(fn ($value) => is_array($value) ? $value : [$value]) : $values;
            return ['type' => $type, 'answered_count' => $values->count(), 'distribution' => $choices->map(fn ($value) => (string) $value)->countBy()->sortKeys()];
        })->all();
    }

    private function reportingDimensions(Staff $actor, array $allowed): array
    {
        $assignment = DB::table('hr_employment_assignments')->where('staff_id', $actor->id)->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))->latest('effective_from')->first();
        $joined = DB::table('hr_employment_spells')->where('staff_id', $actor->id)->min('joined_at');
        $years = $joined ? now()->diffInYears($joined) : null;
        $available = [
            'organization_unit' => $assignment?->organization_unit_id, 'location' => $assignment?->location_code,
            'staff_type' => $actor->staff_type,
            'tenure_band' => $years === null ? 'unspecified' : ($years < 1 ? 'under_1_year' : ($years < 3 ? '1_to_3_years' : ($years < 5 ? '3_to_5_years' : '5_plus_years'))),
        ];
        return collect($available)->only($allowed)->map(fn ($value) => $value ?: 'unspecified')->all();
    }

    private function audience($query, Staff $actor): void
    {
        $assignment = DB::table('hr_employment_assignments')->where('staff_id', $actor->id)->whereDate('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))->latest('effective_from')->first();
        $query->where(function ($q) use ($actor, $assignment) {
            $q->where('audience->all', true)->orWhereJsonContains('audience->staff_ids', $actor->id)->orWhereJsonContains('audience->staff_types', $actor->staff_type);
            if ($assignment?->organization_unit_id) $q->orWhereJsonContains('audience->organization_unit_ids', $assignment->organization_unit_id);
            if ($assignment?->location_code) $q->orWhereJsonContains('audience->location_codes', $assignment->location_code);
        });
    }

    private function actor(Request $request): Staff { return Staff::query()->where('user_id', $request->user()->id)->firstOrFail(); }
    private function company(Request $request, string $companyId): void { abort_unless($this->actor($request)->company_id === $companyId, 403, 'Engagement data is outside your legal entity.'); }
    private function enabled(): void { abort_unless(config('hr.features.engagement_analytics', false), 409, 'HR engagement and analytics writes are not enabled.'); }
}
