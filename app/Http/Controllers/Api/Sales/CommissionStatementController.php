<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionDispute;
use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementLine;
use App\Models\Sales\SalesCommissionPayout;
use App\Models\Sales\SalesCommissionStatementExport;
use App\Models\Sales\SalesProfile;
use App\Services\StaffAccessService;
use App\Services\Sales\CommissionDisputeService;
use App\Services\Sales\CommissionBusinessCalendarService;
use App\Services\Sales\CommissionCycleResolver;
use App\Services\Sales\CommissionPayoutService;
use App\Services\Sales\CommissionAccountingOutboxReconciliationService;
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
        $query = SalesCommissionStatement::query();
        $this->applyScope($query, $request);
        $page = $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('period_end', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->whereDate('period_start', '<=', $to))
            ->latest('period_end')->paginate($this->paginationSize($request, 25));
        $page->getCollection()->transform(static fn (SalesCommissionStatement $statement): array => [
            'id' => (string) $statement->id,
            'statement_number' => $statement->statement_number,
            'period_start' => $statement->period_start->toDateString(),
            'period_end' => $statement->period_end->toDateString(),
            'status' => $statement->status,
            'state_version' => $statement->state_version,
            'opening_carry_forward_lkr' => $statement->opening_carry_forward_lkr,
            'gross_earnings_lkr' => $statement->gross_earnings_lkr,
            'contested_hold_lkr' => $statement->contested_hold_lkr,
            'net_payable_lkr' => $statement->net_payable_lkr,
            'paid_lkr' => $statement->paid_lkr,
            'closing_carry_forward_lkr' => $statement->closing_carry_forward_lkr,
        ]);
        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function show(Request $request, SalesCommissionStatement $statement): JsonResponse
    {
        $query = SalesCommissionStatement::query()->whereKey($statement->id)->with('lines');
        $this->applyScope($query, $request);
        return response()->json(['status' => 'success', 'data' => $this->statementDetailProjection($query->firstOrFail())]);
    }

    public function profileOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = SalesProfile::query()
            ->with(['staff:id,user_id,code', 'staff.user:id,first_name,last_name'])
            ->commissionStatementEligible();
        $this->applyProfileOptionScope($query, $request);
        if (! empty($data['selected_id'])) $query->where('sales_profiles.id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($profiles) => $profiles->where('sales_profiles.sales_code', 'like', $term)
                ->orWhereHas('staff', fn ($staff) => $staff->where('code', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)))
                );
        }

        $rows = $query->orderBy('sales_profiles.sales_code')->orderBy('sales_profiles.id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn (SalesProfile $profile) => [
            'value' => (string) $profile->id,
            'label' => trim($profile->sales_code.' — '.($profile->staff?->user?->first_name.' '.$profile->staff?->user?->last_name)),
            'status' => $profile->status,
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function schedule(Request $request, CommissionCycleResolver $cycles, CommissionBusinessCalendarService $calendars): JsonResponse
    {
        $data = $request->validate([
            'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'reference_date' => ['required', 'date'],
        ]);
        $profile = $this->statementProfile($request, $data['sales_profile_id']);
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
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $statements = SalesCommissionStatement::query();
        $this->applyScope($statements, $request);
        $query = SalesCommissionDispute::query()->whereIn('statement_id', $statements->select('id'));
        $page = $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'open' THEN 1 ELSE 2 END")->latest('raised_at')->paginate($this->paginationSize($request, 25));
        $page->getCollection()->transform(static fn (SalesCommissionDispute $dispute): array => [
            'id' => (string) $dispute->id,
            'category' => $dispute->category,
            'reason' => $dispute->reason,
            'contested_amount_lkr' => $dispute->contested_amount_lkr,
            'status' => $dispute->status,
            'response_due_at' => $dispute->response_due_at,
            'evidence_file_id' => $dispute->evidence_file_id,
        ]);
        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['confirmed', 'reversal', 'reversed'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $visibleStatements = SalesCommissionStatement::query();
        $this->applyScope($visibleStatements, $request);
        $query = SalesCommissionPayout::query()->whereIn('staff_id', $visibleStatements->select('staff_id')->distinct());
        $page = $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('paid_at')->paginate($this->paginationSize($request, 25));
        $page->getCollection()->transform(static fn (SalesCommissionPayout $payout): array => [
            'id' => (string) $payout->id,
            'payout_number' => $payout->payout_number,
            'amount_lkr' => $payout->amount_lkr,
            'payment_reference' => $payout->payment_reference,
            'status' => $payout->status,
            'accounting_status' => $payout->accounting_status,
            'evidence_file_id' => $payout->evidence_file_id,
        ]);
        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function accountingReconciliation(Request $request, CommissionAccountingOutboxReconciliationService $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $reconciliation->preview($data['per_page'] ?? 50),
        ]);
    }

    public function preview(Request $request, CommissionStatementService $statements): JsonResponse
    {
        $data = $this->statementInput($request);
        $profile = $this->statementProfile($request, $data['sales_profile_id']);
        return response()->json(['status' => 'success', 'data' => $this->previewProjection($statements->preview($profile, $data))]);
    }

    public function generate(Request $request, CommissionStatementService $statements): JsonResponse
    {
        $data = $this->statementInput($request, true);
        $profile = $this->statementProfile($request, $data['sales_profile_id']);
        $statement = $statements->generate($profile, $data, (string) $request->user()->id);
        return response()->json(['status' => 'success', 'data' => [
            'status' => $statement->status,
            'period_start' => $statement->period_start,
            'period_end' => $statement->period_end,
        ]], 201);
    }

    public function transition(Request $request, SalesCommissionStatement $statement, CommissionStatementService $statements): JsonResponse
    {
        $data = $request->validate([
            'to_status' => ['required', Rule::in(['draft', 'pending_approval', 'approved', 'void'])],
            'expected_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertStatementScope($request, (string) $statement->id);
        $updated = $statements->transition(
            $statement, $data['to_status'], $data['expected_version'], $data['reason'],
            $data['idempotency_key'], (string) $request->user()->id,
        );
        return response()->json(['status' => 'success', 'data' => [
            'status' => $updated->status,
            'state_version' => $updated->state_version,
        ]]);
    }

    public function raiseDispute(Request $request, SalesCommissionStatementLine $line, CommissionDisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['earning', 'hold', 'rate', 'ownership', 'recovery', 'other'])],
            'reason' => ['required', 'string', 'max:2000'], 'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
            'contested_amount_lkr' => ['required', 'numeric', 'gt:0'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $staff = app(StaffAccessService::class)->currentActorStaff($request->user());
        $dispute = $disputes->raise($line, (string) $staff->id, $data);
        return response()->json(['status' => 'success', 'data' => [
            'status' => $dispute->status,
        ]], 201);
    }

    public function resolveDispute(Request $request, SalesCommissionDispute $dispute, CommissionDisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in(['reject_claim', 'uphold_adjustment'])],
            'adjustment_lkr' => ['nullable', 'required_if:resolution,uphold_adjustment', 'numeric', 'not_in:0'],
            'resolution_reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertStatementScope($request, $dispute->statement_id);
        $resolved = $disputes->resolve($dispute, $data, (string) $request->user()->id);
        return response()->json(['status' => 'success', 'data' => [
            'status' => $resolved->status,
        ]]);
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
        $visibleStatements = SalesCommissionStatement::query()->whereIn('id', array_unique($data['statement_ids']));
        $this->applyScope($visibleStatements, $request);
        abort_unless($visibleStatements->count() === count(array_unique($data['statement_ids'])), 403,
            'Payout statements are outside your authorized Staff scope.');
        $payout = $payouts->pay($data['statement_ids'], $data, (string) $request->user()->id);
        return response()->json(['status' => 'success', 'data' => [
            'status' => $payout->status,
            'accounting_status' => $payout->accounting_status,
        ]], 201);
    }

    public function reversePayout(Request $request, SalesCommissionPayout $payout, CommissionPayoutService $payouts): JsonResponse
    {
        $this->assertPayoutScope($request, (string) $payout->id);
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'max:50'], 'payment_reference' => ['required', 'string', 'max:160'],
            'reversed_at' => ['required', 'date', 'before_or_equal:now'],
            'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $reversal = $payouts->reverse($payout, $data, (string) $request->user()->id);
        return response()->json(['status' => 'success', 'data' => [
            'status' => $reversal->status,
            'accounting_status' => $reversal->accounting_status,
        ]], 201);
    }

    public function recordAccountingDelivery(Request $request, SalesCommissionPayout $payout, CommissionPayoutService $payouts): JsonResponse
    {
        $this->assertPayoutScope($request, (string) $payout->id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['failed'])],
            'message' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $delivery = $payouts->recordAccountingDelivery(
            $payout, $data, (string) $request->user()->id,
        );
        return response()->json(['status' => 'success', 'data' => [
            'status' => $delivery->status,
            'event_type' => $delivery->event_type,
            'recorded_at' => $delivery->recorded_at->toIso8601String(),
        ]], 201);
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
            'id' => $export->id, 'file_name' => $export->file_name,
        ]], 201);
    }

    public function downloadExport(Request $request, SalesCommissionStatementExport $export): StreamedResponse
    {
        $statement = SalesCommissionStatement::query()->whereKey($export->statement_id);
        $this->applyScope($statement, $request);
        $statement = $statement->firstOrFail();
        $expectedPath = '#^commission-statements/(?:[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/)?'.preg_quote((string) $statement->staff_id, '#').'/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\\.'.preg_quote((string) $export->format, '#').'$#i';
        abort_unless($export->disk === 'sales_private'
            && in_array($export->format, ['pdf', 'csv'], true)
            && preg_match($expectedPath, (string) $export->path) === 1, 404, 'Statement export file is unavailable.');
        $disk = Storage::disk($export->disk);
        abort_unless($disk->exists($export->path), 404, 'Statement export file is unavailable.');
        $content = $disk->get($export->path);
        abort_unless(is_string($content)
            && strlen($content) === (int) $export->file_size
            && hash_equals((string) $export->file_checksum, hash('sha256', $content)), 409,
            'Statement export integrity verification failed.');
        $export->update(['last_downloaded_at' => now(), 'last_downloaded_by' => $request->user()->id]);

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, $export->file_name, ['Content-Length' => (string) strlen($content)]);
    }

    private function statementInput(Request $request, bool $requireKey = false): array
    {
        $data = $request->validate([
            'sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'cutoff_at' => ['required', 'date', 'after_or_equal:period_end'],
            'idempotency_key' => [$requireKey ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
        return $data;
    }

    private function previewProjection(array $facts): array
    {
        return [
            'period_start' => $facts['period_start']->toDateString(),
            'period_end' => $facts['period_end']->toDateString(),
            'cutoff_at' => $facts['cutoff_at']->toIso8601String(),
            'finalization_at' => $facts['finalization_at']->toIso8601String(),
            'approval_deadline_at' => $facts['approval_deadline_at']->toIso8601String(),
            'settlement_at' => $facts['settlement_at']->toIso8601String(),
            'opening_carry_forward_lkr' => $facts['opening_carry_forward_lkr'],
            'gross_earnings_lkr' => $facts['gross_earnings_lkr'],
            'adjustment_credits_lkr' => $facts['adjustment_credits_lkr'],
            'recovery_deductions_lkr' => $facts['recovery_deductions_lkr'],
            'other_deductions_lkr' => $facts['other_deductions_lkr'],
            'net_payable_lkr' => $facts['net_payable_lkr'],
            'closing_carry_forward_lkr' => $facts['closing_carry_forward_lkr'],
            'lines' => array_map(static fn (array $line): array => [
                'line_type' => $line['line_type'],
                'source_type' => $line['source_type'],
                'description' => $line['description'],
                'gross_lkr' => $line['gross_lkr'],
                'deduction_lkr' => $line['deduction_lkr'],
                'net_lkr' => $line['net_lkr'],
                'line_status' => $line['line_status'],
                'hold_code' => $line['hold_code'],
            ], $facts['lines']),
            'write_performed' => false,
        ];
    }

    private function statementDetailProjection(SalesCommissionStatement $statement): array
    {
        return [
            'statement_number' => $statement->statement_number,
            'status' => $statement->status,
            'lines' => $statement->lines->map(static fn (SalesCommissionStatementLine $line): array => [
                'id' => (string) $line->id,
                'line_type' => $line->line_type,
                'description' => $line->description,
                'gross_lkr' => $line->gross_lkr,
                'deduction_lkr' => $line->deduction_lkr,
                'net_lkr' => $line->net_lkr,
                'line_status' => $line->line_status,
                'hold_code' => $line->hold_code,
            ])->values()->all(),
        ];
    }

    private function applyScope($query, Request $request): void
    {
        $profileIds = $this->visibleProfileIds($request);
        if ($profileIds !== null) $query->whereIn('sales_profile_id', $profileIds);
    }

    private function applyProfileOptionScope($query, Request $request): void
    {
        $profileIds = $this->visibleProfileIds($request);
        if ($profileIds !== null) $query->whereIn('id', $profileIds);
    }

    private function visibleProfileIds(Request $request): ?array
    {
        if ($request->user()->can('sales.commission-statements.view-all')) return null;
        $staff = app(StaffAccessService::class)->currentActorStaff($request->user());
        $ids = SalesProfile::query()->where('staff_id', $staff->id)->activeAt(now())->pluck('id')->all();
        abort_if($ids === [], 409, 'An active Sales Profile is required to load commission statements.');
        if ($request->user()->can('sales.commission-statements.view-team')) {
            $pending = $ids;
            while ($pending !== []) {
                $managerIds = array_splice($pending, 0);
                $members = DB::table('sales_reporting_assignments as reporting')
                ->join('sales_profiles as member', 'member.id', '=', 'reporting.member_sales_profile_id')
                ->whereIn('reporting.manager_sales_profile_id', $managerIds)
                ->whereNull('reporting.deleted_at')
                ->where('reporting.effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('reporting.effective_until')->orWhere('reporting.effective_until', '>', now()))
                ->pluck('reporting.member_sales_profile_id')->all();
                foreach ($members as $memberId) {
                    if (! in_array($memberId, $ids, true)) {
                        $ids[] = $memberId;
                        $pending[] = $memberId;
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function statementProfile(Request $request, string $profileId): SalesProfile
    {
        $query = SalesProfile::query()->commissionStatementEligible()->with('staff')->whereKey($profileId);
        $this->applyProfileOptionScope($query, $request);

        return $query->firstOrFail();
    }

    private function assertStatementScope(Request $request, string $statementId): void
    {
        $query = SalesCommissionStatement::query()->whereKey($statementId);
        $this->applyScope($query, $request);
        $query->firstOrFail();
    }

    private function assertPayoutScope(Request $request, string $payoutId): void
    {
        $statementIds = DB::table('sales_commission_payout_allocations')->where('payout_id', $payoutId)->pluck('statement_id');
        abort_if($statementIds->isEmpty(), 404, 'Payout statements were not found.');
        $query = SalesCommissionStatement::query()->whereIn('id', $statementIds);
        $this->applyScope($query, $request);
        abort_unless($query->count() === $statementIds->unique()->count(), 403,
            'Payout statements are outside your authorized Staff scope.');
    }
}
