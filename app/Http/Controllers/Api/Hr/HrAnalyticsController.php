<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Hr\HrAnalyticsSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HrAnalyticsController extends Controller
{
    public function __construct(private readonly HrAnalyticsSnapshotService $snapshots) {}

    public function definitions(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_analytics_definition_versions')->where('company_id', $actor->company_id)->select(['id', 'metric_code', 'version', 'name', 'definition', 'dimension_policy', 'minimum_group_size', 'effective_from', 'effective_until', 'status', 'definition_checksum'])->orderBy('metric_code')->orderByDesc('version')->paginate($request->integer('per_page', 50))]);
    }

    public function storeDefinition(Request $request): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate([
            'metric_code' => ['required', 'string', 'max:100'], 'version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:255'],
            'definition' => ['required', 'string', 'max:10000'], 'source_contract' => ['required', 'array'],
            'source_contract.metric_kind' => ['required', Rule::in(['headcount', 'joiners', 'leavers', 'vacant_positions', 'approved_leave_minutes', 'approved_overtime_minutes', 'learning_completions'])],
            'source_contract.period_kind' => ['required', Rule::in(['day', 'month', 'year_to_date'])], 'source_contract.unit' => ['required', Rule::in(['count', 'minutes'])],
            'dimension_policy' => ['required', 'array'], 'dimension_policy.allowed_dimensions' => ['required', 'array'],
            'dimension_policy.allowed_dimensions.*' => ['required', Rule::in(['organization_unit', 'location', 'staff_type', 'tenure_band'])],
            'minimum_group_size' => ['required', 'integer', 'min:5', 'max:1000'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $dimensions = $data['dimension_policy']['allowed_dimensions']; abort_if(count($dimensions) !== count(array_unique($dimensions)), 422, 'Allowed analytics dimensions must be unique.'); $expectedUnit = in_array($data['source_contract']['metric_kind'], ['approved_leave_minutes', 'approved_overtime_minutes'], true) ? 'minutes' : 'count'; abort_unless($data['source_contract']['unit'] === $expectedUnit, 422, 'Metric unit does not match the selected source fact.');
        $checksum = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); $id = (string) Str::uuid();
        foreach (['source_contract', 'dimension_policy'] as $key) $data[$key] = json_encode($data[$key], JSON_THROW_ON_ERROR);
        DB::table('hr_analytics_definition_versions')->insert($data + ['id' => $id, 'company_id' => $actor->company_id, 'status' => 'pending_approval', 'created_by' => $request->user()->id, 'definition_checksum' => $checksum, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_analytics_definition_versions')->find($id)], 201);
    }

    public function approveDefinition(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        return DB::transaction(function () use ($request, $actor, $id) {
            $row = DB::table('hr_analytics_definition_versions')->where('id', $id)->where('company_id', $actor->company_id)->lockForUpdate()->first(); abort_unless($row, 404);
            abort_unless($row->status === 'pending_approval', 409); abort_if($row->created_by === $request->user()->id, 409, 'Definition creator cannot approve the same version.');
            $overlap = DB::table('hr_analytics_definition_versions')->where('company_id', $actor->company_id)->where('metric_code', $row->metric_code)->where('status', 'approved')->where('effective_from', '<=', $row->effective_until ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $row->effective_from))->exists();
            abort_if($overlap, 409, 'Approved metric definition effective dates cannot overlap.');
            DB::table('hr_analytics_definition_versions')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_analytics_definition_versions')->find($id)]);
        });
    }

    public function rejectDefinition(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request); $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        return DB::transaction(function () use ($request, $actor, $data, $id) {
            $row = DB::table('hr_analytics_definition_versions')->where('id', $id)->where('company_id', $actor->company_id)->lockForUpdate()->first(); abort_unless($row, 404);
            abort_unless($row->status === 'pending_approval', 409); abort_if($row->created_by === $request->user()->id, 409, 'Definition creator cannot reject the same version.');
            DB::table('hr_analytics_definition_versions')->where('id', $id)->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_reason' => $data['reason'], 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_analytics_definition_versions')->find($id)]);
        });
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $this->enabled(); $actor = $this->actor($request);
        $data = $request->validate(['as_of_date' => ['required', 'date'], 'dimension' => ['nullable', Rule::in(['organization_unit', 'location', 'staff_type', 'tenure_band'])]]);
        $definition = DB::table('hr_analytics_definition_versions')->where('id', $id)->where('company_id', $actor->company_id)->where('status', 'approved')->whereDate('effective_from', '<=', $data['as_of_date'])->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $data['as_of_date']))->first(); abort_unless($definition, 404);
        $built = $this->snapshots->build($definition, $data['as_of_date'], ['dimension' => $data['dimension'] ?? null]);
        $snapshotPayload = ['definition_checksum' => $definition->definition_checksum, 'as_of_date' => $data['as_of_date'], 'filter' => $built['filter_snapshot'], 'aggregate' => $built['aggregate_payload'], 'suppression' => $built['suppression_snapshot'], 'source_checksum' => $built['source_checksum']];
        $snapshotChecksum = hash('sha256', json_encode($snapshotPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $existing = DB::table('hr_analytics_snapshots')->where('definition_version_id', $id)->where('as_of_date', $data['as_of_date'])->where('source_checksum', $built['source_checksum'])->first();
        if ($existing) return response()->json(['status' => 'success', 'data' => $existing, 'idempotent_replay' => true]);
        $snapshotId = (string) Str::uuid();
        DB::table('hr_analytics_snapshots')->insert(['id' => $snapshotId, 'company_id' => $actor->company_id, 'definition_version_id' => $id, 'as_of_date' => $data['as_of_date'], 'cutoff_at' => now(), 'filter_snapshot' => json_encode($built['filter_snapshot'], JSON_THROW_ON_ERROR), 'aggregate_payload' => json_encode($built['aggregate_payload'], JSON_THROW_ON_ERROR), 'suppression_snapshot' => json_encode($built['suppression_snapshot'], JSON_THROW_ON_ERROR), 'source_checksum' => $built['source_checksum'], 'snapshot_checksum' => $snapshotChecksum, 'generated_by' => $request->user()->id, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_analytics_snapshots')->find($snapshotId)], 201);
    }

    public function snapshotIndex(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = DB::table('hr_analytics_snapshots as s')->join('hr_analytics_definition_versions as d', 'd.id', '=', 's.definition_version_id')->where('s.company_id', $actor->company_id);
        if ($request->filled('metric_code')) $query->where('d.metric_code', $request->string('metric_code'));
        return response()->json(['status' => 'success', 'data' => $query->select(['s.id', 's.definition_version_id', 'd.metric_code', 'd.name', 's.as_of_date', 's.cutoff_at', 's.filter_snapshot', 's.aggregate_payload', 's.suppression_snapshot', 's.source_checksum', 's.snapshot_checksum', 's.generated_at'])->latest('s.generated_at')->paginate($request->integer('per_page', 50))]);
    }

    private function actor(Request $request): Staff { return Staff::query()->where('user_id', $request->user()->id)->firstOrFail(); }
    private function enabled(): void { abort_unless(config('hr.features.engagement_analytics', false), 409, 'HR engagement and analytics writes are not enabled.'); }
}
