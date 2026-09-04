<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionDispute;
use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementLine;
use App\Models\Sales\SalesCommissionPayout;
use App\Models\Sales\SalesCommissionStatementExport;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Services\Sales\CommissionDisputeService;
use App\Services\Sales\CommissionBusinessCalendarService;
use App\Services\Sales\CommissionCycleResolver;
use App\Services\Sales\CommissionPayoutService;
use App\Services\Sales\CommissionStatementService;
use App\Services\Sales\CommissionStatementExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommissionStatementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'approved', 'partially_paid', 'paid', 'void'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCommissionStatement::query()->withCount('lines');
        $this->applyScope($query, $request);
        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('period_end', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->whereDate('period_start', '<=', $to))
            ->latest('period_end')->paginate($request->integer('per_page', 25))]);
    }

    public function show(Request $request, SalesCommissionStatement $statement): JsonResponse
    {
        $query = SalesCommissionStatement::query()->whereKey($statement->id)->with('lines');
        $this->applyScope($query, $request);
        return response()->json(['status' => 'success', 'data' => $query->firstOrFail()]);
    }

    public function managementContext(Request $request): JsonResponse
    {
        $companyIds = $this->actorCompanyIds($request);
        $profiles = SalesProfile::query()->with('staff:id,code,staff_type')->whereIn('company_id', $companyIds)
            ->activeAt(now())->whereHas('staff', fn ($query) => $query->where(fn ($active) => $active->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
            ->orderBy('sales_code')->get(['id', 'company_id', 'staff_id', 'sales_code']);
        return response()->json(['status' => 'success', 'data' => ['profiles' => $profiles]]);
    }

    public function schedule(Request $request, CommissionCycleResolver $cycles, CommissionBusinessCalendarService $calendars): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'reference_date' => ['required', 'date'],
        ]);
        $profile = SalesProfile::query()->with('staff')->findOrFail($data['sales_profile_id']);
        $this->assertManagementCompany($request, $profile->company_id);
        $resolved = $cycles->resolve($profile, CarbonImmutable::parse($data['reference_date']));
        $cycle = $resolved['cycle'];
        $schedule = $calendars->schedule($cycle, CarbonImmutable::parse($data['reference_date'], $cycle->timezone));
        return response()->json(['status' => 'success', 'data' => [
            'cycle_version_id' => $cycle->id, 'cycle_assignment_id' => $resolved['assignment']->id,
            'business_calendar_id' => $schedule['calendar']->id, 'timezone' => $cycle->timezone,
            'earning_period_rule' => $cycle->earning_period_rule, 'holiday_rule' => $cycle->holiday_rule,
            'period_start' => $schedule['period_start']->toDateString(), 'period_end' => $schedule['period_end']->toDateString(),
            'cutoff_at' => $schedule['cutoff_at']->toIso8601String(),
            'finalization_at' => $schedule['finalization_at']->toIso8601String(),
            'approval_deadline_at' => $schedule['approval_deadline_at']->toIso8601String(),
            'settlement_at' => $schedule['settlement_at']->toIso8601String(),
            'write_performed' => false,
        ]]);
    }

    public function disputes(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'resolved'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = SalesCommissionDispute::query()->whereIn('company_id', $this->actorCompanyIds($request));
        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'open' THEN 1 ELSE 2 END")->latest('raised_at')->paginate($request->integer('per_page', 25))]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['confirmed', 'reversal', 'reversed'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = SalesCommissionPayout::query()->whereIn('company_id', $this->actorCompanyIds($request));
        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('paid_at')->paginate($request->integer('per_page', 25))]);
    }

    public function preview(Request $request, CommissionStatementService $statements): JsonResponse
    {
        $data = $this->statementInput($request);
        $profile = SalesProfile::query()->with('staff')->findOrFail($data['sales_profile_id']);
        $this->assertManagementCompany($request, $profile->company_id);
        return response()->json(['status' => 'success', 'data' => $statements->preview($profile, $data)]);
    }

    public function generate(Request $request, CommissionStatementService $statements): JsonResponse
    {
        $data = $this->statementInput($request, true);
        $profile = SalesProfile::query()->with('staff')->findOrFail($data['sales_profile_id']);
        $this->assertManagementCompany($request, $profile->company_id);
        return response()->json(['status' => 'success', 'data' => $statements->generate($profile, $data, (string) $request->user()->id)], 201);
    }

    public function transition(Request $request, SalesCommissionStatement $statement, CommissionStatementService $statements): JsonResponse
    {
        $data = $request->validate([
            'to_status' => ['required', Rule::in(['draft', 'pending_approval', 'approved', 'void'])],
            'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertManagementCompany($request, $statement->company_id);
        return response()->json(['status' => 'success', 'data' => $statements->transition(
            $statement, $data['to_status'], $data['expected_version'], $data['reason'],
            $data['idempotency_key'], (string) $request->user()->id,
        )]);
    }

    public function raiseDispute(Request $request, SalesCommissionStatementLine $line, CommissionDisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['earning', 'hold', 'rate', 'ownership', 'recovery', 'other'])],
            'reason' => ['required', 'string', 'max:2000'], 'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
            'contested_amount_lkr' => ['required', 'numeric', 'gt:0'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $staffId = Staff::query()->where('user_id', $request->user()->id)
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))->value('id');
        abort_unless($staffId, 403, 'An active Staff identity is required.');
        return response()->json(['status' => 'success', 'data' => $disputes->raise($line, $staffId, $data)], 201);
    }

    public function resolveDispute(Request $request, SalesCommissionDispute $dispute, CommissionDisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['reject_claim', 'uphold_adjustment'])],
            'adjustment_lkr' => ['nullable', 'required_if:resolution,uphold_adjustment', 'numeric', 'not_in:0'],
            'resolution_reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertManagementCompany($request, $dispute->company_id);
        return response()->json(['status' => 'success', 'data' => $disputes->resolve($dispute, $data, (string) $request->user()->id)]);
    }

    public function pay(Request $request, CommissionPayoutService $payouts): JsonResponse
    {
        $data = $request->validate([
            'statement_ids' => ['required', 'array', 'min:1', 'max:100'], 'statement_ids.*' => ['uuid', 'exists:sales_commission_statements,id'],
            'amount_lkr' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', 'string', 'max:50'],
            'payment_account_snapshot' => ['required', 'array'], 'payment_reference' => ['required', 'string', 'max:160'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'], 'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $companyIds = SalesCommissionStatement::query()->whereIn('id', array_unique($data['statement_ids']))->pluck('company_id')->unique();
        abort_unless($companyIds->count() === 1, 422, 'A payout must contain statements from one legal entity.');
        $this->assertManagementCompany($request, (string) $companyIds->first());
        return response()->json(['status' => 'success', 'data' => $payouts->pay($data['statement_ids'], $data, (string) $request->user()->id)], 201);
    }

    public function reversePayout(Request $request, SalesCommissionPayout $payout, CommissionPayoutService $payouts): JsonResponse
    {
        $this->assertManagementCompany($request, $payout->company_id);
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:50'], 'payment_reference' => ['required', 'string', 'max:160'],
            'reversed_at' => ['required', 'date', 'before_or_equal:now'],
            'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        return response()->json(['status' => 'success', 'data' => $payouts->reverse($payout, $data, (string) $request->user()->id)], 201);
    }

    public function recordAccountingDelivery(Request $request, SalesCommissionPayout $payout, CommissionPayoutService $payouts): JsonResponse
    {
        $this->assertManagementCompany($request, $payout->company_id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'failed'])],
            'external_reference' => ['nullable', 'required_if:status,accepted', 'string', 'max:160'],
            'message' => ['nullable', 'required_if:status,failed', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        return response()->json(['status' => 'success', 'data' => $payouts->recordAccountingDelivery(
            $payout, $data, (string) $request->user()->id,
        )], 201);
    }

    public function export(Request $request, SalesCommissionStatement $statement, CommissionStatementExportService $exports): JsonResponse
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['pdf', 'csv'])],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $query = SalesCommissionStatement::query()->whereKey($statement->id);
        $this->applyScope($query, $request);
        $statement = $query->firstOrFail();
        $export = $exports->generate(
            $statement, $data['format'], $data['idempotency_key'], (string) $request->user()->id,
        );
        return response()->json(['status' => 'success', 'data' => [
            'id' => $export->id, 'statement_id' => $export->statement_id, 'format' => $export->format,
            'file_name' => $export->file_name, 'file_checksum' => $export->file_checksum,
            'file_size' => $export->file_size, 'generated_at' => $export->generated_at,
        ]], 201);
    }

    public function downloadExport(Request $request, SalesCommissionStatementExport $export): StreamedResponse
    {
        $statement = SalesCommissionStatement::query()->whereKey($export->statement_id);
        $this->applyScope($statement, $request);
        $statement->firstOrFail();
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404, 'Statement export file is unavailable.');
        $export->update(['last_downloaded_at' => now(), 'last_downloaded_by' => $request->user()->id]);
        return Storage::disk($export->disk)->download($export->path, $export->file_name);
    }

    private function statementInput(Request $request, bool $requireKey = false): array
    {
        return $request->validate([
            'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'cutoff_at' => ['required', 'date', 'after_or_equal:period_end'],
            'idempotency_key' => [$requireKey ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
    }

    private function applyScope($query, Request $request): void
    {
        if ($request->user()->can('sales.commission-statements.view-all')) return;
        $profile = SalesProfile::query()->whereHas('staff', fn ($staff) => $staff->where('user_id', $request->user()->id))->activeAt(now())->firstOrFail();
        $ids = [$profile->id];
        if ($request->user()->can('sales.commission-statements.view-team')) {
            $ids = array_merge($ids, DB::table('sales_reporting_assignments')->where('manager_sales_profile_id', $profile->id)
                ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->pluck('member_sales_profile_id')->all());
        }
        $query->whereIn('sales_profile_id', array_values(array_unique($ids)));
    }

    private function assertManagementCompany(Request $request, string $companyId): void
    {
        if ($request->user()->can('sales.commission-statements.view-all')) return;
        abort_unless(in_array($companyId, $this->actorCompanyIds($request), true), 403, 'Commission operation is outside your legal entity.');
    }

    private function actorCompanyIds(Request $request): array
    {
        if ($request->user()->can('sales.commission-statements.view-all')) {
            return DB::table('companies')->pluck('id')->all();
        }
        return Staff::query()->where('user_id', $request->user()->id)
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->pluck('company_id')->filter()->unique()->values()->all();
    }
}
