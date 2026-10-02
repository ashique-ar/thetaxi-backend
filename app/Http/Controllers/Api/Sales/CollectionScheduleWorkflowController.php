<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingCollectionSubmission;
use App\Models\Booking\BookingCollectionWorkItem;
use App\Models\Booking\BookingPaymentScheduleRule;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Services\Sales\CollectionScheduleWorkflowService;
use App\Services\Sales\CollectionWorkAgingService;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\BookingPaymentLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CollectionScheduleWorkflowController extends Controller
{
    public function __construct(
        private readonly CollectionScheduleWorkflowService $workflow,
        private readonly CollectionWorkAgingService $aging,
        private readonly BookingPaymentLedgerService $ledger,
        private readonly SalesAccessScope $access,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function createRollingRule(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'contract_basis' => ['required', Rule::in(['open_ended'])],
            'anchor_date' => ['required', 'date', 'after_or_equal:today'],
            'monthly_source_amount' => ['required', 'numeric', 'gt:0'],
            'source_currency' => ['required', 'string', 'size:3'],
            'is_collection_target_eligible' => ['required', 'boolean'],
            'reminder_offset_days' => ['required', 'integer', 'min:0', 'max:90'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertBookingManagementScope($request, $booking);

        return response()->json([
            'status' => 'success',
            'data' => $this->ledger->createRollingScheduleRule($booking, $data, (string) $request->user()->id),
        ], 201);
    }

    public function transitionRollingRule(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['pause', 'resume', 'end'])],
            'effective_at' => ['required', 'date', 'before_or_equal:now'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertBookingManagementScope($request, $booking);

        return response()->json([
            'status' => 'success',
            'data' => $this->ledger->transitionRollingScheduleRule($booking, $data, (string) $request->user()->id),
        ]);
    }

    public function revise(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'effective_at' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
            'contract_basis' => ['required', Rule::in(['fixed_term', 'open_ended_ended_rule'])],
            'reconciliation_rule' => ['required', Rule::in(['exact', 'opening', 'balloon', 'residual'])],
            'preview_checksum' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'items' => ['required', 'array', 'min:1', 'max:120'],
            'items.*.label' => ['nullable', 'string', 'max:120'],
            'items.*.period_start' => ['nullable', 'date'],
            'items.*.period_end' => ['nullable', 'date', 'after_or_equal:items.*.period_start'],
            'items.*.due_date' => ['required', 'date', 'after_or_equal:effective_at'],
            'items.*.source_amount' => ['required', 'numeric', 'gt:0'],
            'items.*.source_currency' => ['required', 'string', 'size:3'],
            'items.*.lkr_amount' => ['nullable', 'numeric', 'gt:0'],
            'items.*.schedule_kind' => ['required', Rule::in(['initial', 'monthly', 'custom'])],
            'items.*.reconciliation_role' => ['nullable', Rule::in(['opening', 'balloon', 'residual'])],
            'items.*.is_collection_target_eligible' => ['required', 'boolean'],
            'items.*.reminder_offset_days' => ['required', 'integer', 'min:0', 'max:90'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->assertBookingManagementScope($request, $booking);

        return response()->json([
            'status' => 'success',
            'data' => $this->workflow->reviseFutureUnpaid($booking, $data, (string) $request->user()->id),
        ], 201);
    }

    public function previewRevision(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'effective_at' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
            'contract_basis' => ['required', Rule::in(['fixed_term', 'open_ended_ended_rule'])],
            'reconciliation_rule' => ['required', Rule::in(['exact', 'opening', 'balloon', 'residual'])],
            'items' => ['required', 'array', 'min:1', 'max:120'],
            'items.*.label' => ['nullable', 'string', 'max:120'],
            'items.*.period_start' => ['nullable', 'date'],
            'items.*.period_end' => ['nullable', 'date', 'after_or_equal:items.*.period_start'],
            'items.*.due_date' => ['required', 'date', 'after_or_equal:effective_at'],
            'items.*.source_amount' => ['required', 'numeric', 'gt:0'],
            'items.*.source_currency' => ['required', 'string', 'size:3'],
            'items.*.lkr_amount' => ['nullable', 'numeric', 'gt:0'],
            'items.*.schedule_kind' => ['required', Rule::in(['initial', 'monthly', 'custom'])],
            'items.*.reconciliation_role' => ['nullable', Rule::in(['opening', 'balloon', 'residual'])],
            'items.*.is_collection_target_eligible' => ['required', 'boolean'],
            'items.*.reminder_offset_days' => ['required', 'integer', 'min:0', 'max:90'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->assertBookingManagementScope($request, $booking);

        return response()->json([
            'status' => 'success',
            'data' => $this->workflow->previewFutureUnpaidRevision($booking, $data),
        ]);
    }

    public function scheduleBookings(Request $request): JsonResponse
    {
        $query = DB::table('sales_booking_attributions as attribution')
            ->join('bookings as booking', 'booking.id', '=', 'attribution.booking_id')
            ->leftJoin('booking_payment_schedules as schedule', function ($join): void {
                $join->on('schedule.booking_id', '=', 'booking.id')
                    ->whereNull('schedule.deleted_at')
                    ->where('schedule.status', '!=', 'superseded');
            })
            ->whereNull('attribution.deleted_at')
            ->where(fn ($scope) => $scope->where('attribution.commission_category', 'long_term')->orWhereNotNull('schedule.id'));
        $profileIds = $this->scheduleProfileIds($request);
        if ($profileIds !== null) {
            $query->whereIn('attribution.collection_sales_profile_id', $profileIds);
        }
        return response()->json(['status' => 'success', 'data' => $query
            ->select(['booking.id', 'booking.booking_number', 'attribution.company_id'])
            ->selectRaw('COUNT(schedule.id) as installment_count')
            ->groupBy('booking.id', 'booking.booking_number', 'attribution.company_id')->orderByDesc('booking.booking_number')->get()]);
    }

    public function schedule(Request $request, Booking $booking): JsonResponse
    {
        $this->assertBookingManagementScope($request, $booking);
        $scheduleColumns = ['schedule.id', 'schedule.sequence', 'schedule.label', 'schedule.period_start', 'schedule.period_end', 'schedule.due_date', 'schedule.source_amount', 'schedule.source_currency', 'schedule.lkr_amount', 'schedule.schedule_kind', 'schedule.is_collection_target_eligible', 'schedule.notes', 'schedule.status', 'schedule.revision_number'];
        if (Schema::hasColumn('booking_payment_schedules', 'booking_payment_schedule_rule_id')) {
            $scheduleColumns = [...$scheduleColumns, 'schedule.booking_payment_schedule_rule_id', 'schedule.rule_occurrence_number'];
        }
        if (Schema::hasColumn('booking_payment_schedules', 'reconciliation_role')) {
            $scheduleColumns[] = 'schedule.reconciliation_role';
        }
        $rows = DB::table('booking_payment_schedules as schedule')->leftJoin('booking_payment_schedule_allocations as allocation', function ($join): void {
            $join->on('allocation.booking_payment_schedule_id', '=', 'schedule.id')->whereNull('allocation.deleted_at');
        })->where('schedule.booking_id', $booking->id)->whereNull('schedule.deleted_at')
            ->select($scheduleColumns)
            ->selectRaw('COALESCE(SUM(allocation.amount), 0) as allocated_amount')->groupBy($scheduleColumns)->orderBy('schedule.sequence')->get();
        $rule = Schema::hasTable('booking_payment_schedule_rules')
            ? BookingPaymentScheduleRule::query()->where('booking_id', $booking->id)->first()
            : null;
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
        $rollingEnabled = $attribution?->company_id
            && $this->policySettings->featureEnabled((string) $attribution->company_id, 'rolling_payment_schedules')
            && Schema::hasTable('booking_payment_schedule_rules');
        $rollingHandlerEligible = $attribution?->collection_sales_profile_id
            ? SalesProfile::query()
                ->whereKey($attribution->collection_sales_profile_id)
                ->where('company_id', $attribution->company_id)
                ->eligibleAt('collection', now())
                ->exists()
            : false;
        $requiresRollingScheduleReconciliation = DB::table('booking_payment_schedules')
            ->where('booking_id', $booking->id)->where('schedule_kind', '!=', 'initial')->exists();
        $revisions = collect();
        if (Schema::hasTable('booking_payment_schedule_revisions')) {
            $revisionColumns = ['id', 'revision_number', 'effective_at', 'reason', 'approved_at', 'created_at'];
            if (Schema::hasColumn('booking_payment_schedule_revisions', 'contractual_source_amount')) {
                $revisionColumns = [...$revisionColumns, 'contract_basis', 'reconciliation_rule', 'contractual_source_amount',
                    'source_currency', 'retained_source_amount', 'replacement_source_amount', 'reconciliation_amount',
                    'contractual_lkr_amount', 'retained_lkr_amount', 'replacement_lkr_amount'];
            }
            $revisions = DB::table('booking_payment_schedule_revisions')->where('booking_id', $booking->id)
                ->when(Schema::hasColumn('booking_payment_schedule_revisions', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
                ->orderByDesc('revision_number')->limit(20)->get($revisionColumns);
        }
        return response()->json(['status' => 'success', 'data' => [
            'booking' => ['id' => $booking->id, 'booking_number' => $booking->booking_number, 'total' => $booking->total_actual ?? $booking->total_estimated ?? $booking->amount_to_pay],
            'schedules' => $rows,
            'revisions' => $revisions,
            'rolling_rule' => $rule ? [
                'id' => $rule->id, 'contract_basis' => $rule->contract_basis, 'frequency' => $rule->frequency,
                'anchor_date' => $rule->anchor_date, 'monthly_source_amount' => $rule->source_amount,
                'source_currency' => $rule->source_currency, 'monthly_lkr_amount' => $rule->lkr_amount,
                'is_collection_target_eligible' => $rule->is_collection_target_eligible,
                'reminder_offset_days' => $rule->reminder_offset_days, 'horizon_months' => $rule->horizon_months,
                'status' => $rule->status, 'version' => $rule->version,
                'last_generated_occurrence' => $rule->last_generated_occurrence,
                'last_generated_through' => $rule->last_generated_through,
                'lifetime_contract_value' => null,
                'lifetime_contract_value_state' => 'not_applicable_open_ended',
            ] : null,
            'rolling_rule_enabled' => $rollingEnabled,
            'rolling_rule_context' => [
                'can_create' => $rollingEnabled && ! $rule
                    && ! $requiresRollingScheduleReconciliation
                    && $attribution?->status === 'active'
                    && $attribution?->commission_category === 'long_term'
                    && $rollingHandlerEligible
                    && $attribution?->contract_value_source !== null
                    && $attribution?->source_currency !== null,
                'commission_category' => $attribution?->commission_category,
                'reviewed_monthly_source_amount' => $attribution?->contract_value_source,
                'source_currency' => $attribution?->source_currency,
                'reviewed_monthly_lkr_amount' => $attribution?->contract_value_lkr,
                'collection_sales_profile_id' => $attribution?->collection_sales_profile_id,
                'collection_handler_eligible' => $rollingHandlerEligible,
                'requires_schedule_reconciliation' => $requiresRollingScheduleReconciliation,
            ],
            'fixed_term_context' => ! $rule && $attribution ? [
                'contract_basis_requires_review' => true,
                'attribution_status' => $attribution->status,
                'contractual_source_amount' => $attribution->contract_value_source,
                'source_currency' => $attribution->source_currency,
                'contractual_lkr_amount' => $attribution->contract_value_lkr,
            ] : null,
        ]]);
    }

    public function workItems(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'upcoming', 'due', 'overdue', 'completed', 'cancelled'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = BookingCollectionWorkItem::query()
            ->with([
                'booking:id,booking_number,customer_id',
                'booking.customer:id,user_id,code',
                'booking.customer.user:id,first_name,last_name,email,phone',
                'paymentSchedule' => fn ($schedule) => $schedule->withSum('allocations', 'amount'),
            ])
            ->addSelect([
                'last_submission_at' => BookingCollectionSubmission::query()
                    ->selectRaw('MAX(created_at)')
                    ->whereColumn('booking_id', 'booking_collection_work_items.booking_id')
                    ->whereColumn('booking_payment_schedule_id', 'booking_collection_work_items.booking_payment_schedule_id'),
            ]);
        $this->applyProfileScope($query, $request, 'assigned_sales_profile_id');
        $canViewCustomerContact = $request->user()->can('sales.collections.customer-contact.view');

        $rows = $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('due_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('due_at', '<=', $to))
            ->orderBy('due_at')->paginate($request->integer('per_page', 25));

        $rows->setCollection($rows->getCollection()->map(function ($row) use ($canViewCustomerContact): array {
            $schedule = $row->paymentSchedule;
            $scheduledAmount = round((float) ($schedule?->source_amount ?? $schedule?->amount ?? 0), 4);
            $allocatedAmount = round((float) ($schedule?->allocations_sum_amount ?? 0), 4);
            $dueDate = $schedule?->due_date?->startOfDay() ?? $row->due_at->startOfDay();
            $aging = $this->aging->derive(
                $scheduledAmount,
                $allocatedAmount,
                $dueDate,
                now(),
                (int) $row->reminder_offset_days,
            );
            $customerUser = $row->booking?->customer?->user;
            $lastActivityAt = collect([$row->last_reminded_at, $row->last_submission_at, $row->updated_at])
                ->filter()->map(fn ($value) => (string) $value)->max();

            return [
                'id' => $row->id, 'company_id' => $row->company_id, 'booking_id' => $row->booking_id,
                'booking_number' => $row->booking?->booking_number, 'booking_payment_schedule_id' => $row->booking_payment_schedule_id,
                'work_type' => $row->work_type, 'status' => $row->status, 'due_at' => $row->due_at,
                'promised_date' => $dueDate->toDateString(), 'days_overdue' => $aging['days_overdue'],
                'aging_bucket' => $aging['aging_bucket'], 'last_activity_at' => $lastActivityAt,
                'reminder_offset_days' => $row->reminder_offset_days,
                'last_reminded_at' => $row->last_reminded_at,
                'booking_management_path' => '/bookings/'.$row->booking_id,
                'customer' => $row->booking?->customer ? [
                    'id' => $row->booking->customer->id,
                    'code' => $row->booking->customer->code,
                    'contact_access' => $canViewCustomerContact,
                    'name' => $canViewCustomerContact
                        ? trim(implode(' ', array_filter([$customerUser?->first_name, $customerUser?->last_name])))
                        : null,
                    'email' => $canViewCustomerContact ? $customerUser?->email : null,
                    'phone' => $canViewCustomerContact ? $customerUser?->phone : null,
                ] : null,
                'schedule' => $schedule ? [
                    'label' => $schedule->label, 'due_date' => $schedule->due_date,
                    'source_amount' => $scheduledAmount,
                    'allocated_amount' => $allocatedAmount,
                    'outstanding_amount' => $aging['outstanding_amount'],
                    'source_currency' => $schedule->source_currency ?? 'LKR',
                    'lkr_amount' => $schedule->lkr_amount ?? $schedule->amount,
                    'schedule_kind' => $schedule->schedule_kind,
                    'is_collection_target_eligible' => $schedule->is_collection_target_eligible,
                ] : null,
            ];
        }));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function submit(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'booking_payment_schedule_id' => ['nullable', 'uuid', 'exists:booking_payment_schedules,id'],
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'source_currency' => ['required', 'string', 'size:3'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'online', 'cheque', 'driver_cash', 'other'])],
            'reference' => ['nullable', 'string', 'max:160'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $profile = $this->actorProfile($request);

        return response()->json(['status' => 'success', 'data' => $this->workflow->submit($booking, $profile, $data)], 201);
    }

    public function submissions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['submitted', 'verified', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = BookingCollectionSubmission::query()->with(['booking:id,booking_number', 'paymentSchedule:id,label,due_date']);
        if (! $request->user()->can('sales.collections.verify')) {
            $this->applyProfileScope($query, $request, 'submitted_by_sales_profile_id');
        } elseif (! $request->user()->can('sales.collections.view-all')) {
            $query->whereIn('company_id', $this->actorCompanyIds($request));
        }
        $rows = $query->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('received_at')->paginate($request->integer('per_page', 25));
        $rows->setCollection($rows->getCollection()->map(fn ($row) => [
            'id' => $row->id, 'company_id' => $row->company_id, 'booking_id' => $row->booking_id,
            'booking_number' => $row->booking?->booking_number, 'booking_payment_schedule_id' => $row->booking_payment_schedule_id,
            'schedule_label' => $row->paymentSchedule?->label, 'source_amount' => $row->source_amount,
            'source_currency' => $row->source_currency, 'payment_method' => $row->payment_method,
            'reference' => $row->reference, 'received_at' => $row->received_at, 'status' => $row->status,
            'staff_notes' => $row->staff_notes, 'verification_notes' => $row->verification_notes,
            'evidence_file_id' => $row->evidence_file_id, 'booking_payment_receipt_id' => $row->booking_payment_receipt_id,
            'submitted_by_sales_profile_id' => $row->submitted_by_sales_profile_id,
            'verified_by' => $row->verified_by, 'verified_at' => $row->verified_at,
        ]));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function verify(Request $request, BookingCollectionSubmission $submission): JsonResponse
    {
        if (! $request->user()->can('sales.collections.view-all')) {
            abort_unless(in_array($submission->company_id, $this->actorCompanyIds($request), true), 403, 'Collection submission is outside your legal entity.');
        }
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verify', 'reject'])],
            'verification_notes' => ['nullable', 'required_if:decision,reject', 'string', 'max:2000'],
            'fx_rate_to_lkr' => ['nullable', 'numeric', 'gt:0'],
            'fx_rate_at' => ['nullable', 'date'],
            'fx_source' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->workflow->verify($submission, $data, (string) $request->user()->id),
        ]);
    }

    private function actorProfile(Request $request): SalesProfile
    {
        return $this->access->activeProfile($request->user());
    }

    private function applyProfileScope($query, Request $request, string $column): void
    {
        $profileIds = $this->scheduleProfileIds($request);
        if ($profileIds !== null) {
            $query->whereIn($column, $profileIds);
        }
    }

    private function assertBookingManagementScope(Request $request, Booking $booking): void
    {
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->firstOrFail();
        $profileIds = $this->scheduleProfileIds($request, $attribution->company_id);
        abort_unless(
            $profileIds === null || in_array($attribution->collection_sales_profile_id, $profileIds, true),
            403,
            'Booking is outside your permitted collection scope.',
        );
    }

    private function scheduleProfileIds(Request $request, ?string $companyId = null): ?array
    {
        return $this->access->profileIds(
            $request->user(),
            'sales.collections.view-all',
            'sales.collections.view-team',
            $companyId,
        );
    }

    private function actorCompanyIds(Request $request): array
    {
        return DB::table('staff')->where('user_id', $request->user()->id)->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->pluck('company_id')->filter()->unique()->values()->all();
    }
}
