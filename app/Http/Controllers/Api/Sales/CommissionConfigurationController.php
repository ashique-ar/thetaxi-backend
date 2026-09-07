<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionCycleAssignment;
use App\Models\Sales\SalesCommissionCycleVersion;
use App\Models\Sales\SalesCommissionBusinessCalendar;
use App\Models\Sales\SalesCommissionBusinessCalendarDate;
use App\Models\Sales\SalesCommissionPlanAssignment;
use App\Models\Sales\SalesCommissionPlanFamily;
use App\Models\Sales\SalesCommissionPlanTier;
use App\Models\Sales\SalesCommissionPlanVersion;
use App\Models\Sales\SalesCommissionStaffOverride;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionConfigurationController extends Controller
{
    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->actorCompanyIds($request);
        $query = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($company) => $company->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($company) => $company->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city'])->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => ['value' => (string) $company->id, 'label' => $company->name,
            'metadata' => ['city' => $company->city], 'status' => 'active']);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id']]);
        $this->assertCompanyScope($request, $data['company_id']);
        $familyIds = SalesCommissionPlanFamily::query()->where('company_id', $data['company_id'])->select('id');
        $versionIds = SalesCommissionPlanVersion::query()->whereIn('plan_family_id', $familyIds)->select('id');
        return response()->json(['status' => 'success', 'data' => [
            'families' => SalesCommissionPlanFamily::query()->where('company_id', $data['company_id'])->orderBy('code')->get(),
            'versions' => SalesCommissionPlanVersion::query()->whereIn('plan_family_id', $familyIds)->orderByDesc('effective_from')->get(),
            'tiers' => SalesCommissionPlanTier::query()->whereIn('plan_version_id', $versionIds)->orderBy('sequence')->get(),
            'assignments' => SalesCommissionPlanAssignment::query()->where('company_id', $data['company_id'])->orderByDesc('effective_from')->get(),
            'overrides' => SalesCommissionStaffOverride::query()->where('company_id', $data['company_id'])->orderByDesc('effective_from')->get(),
            'cycles' => SalesCommissionCycleVersion::query()->where('company_id', $data['company_id'])->orderBy('code')->orderByDesc('version')->get(),
            'cycle_assignments' => SalesCommissionCycleAssignment::query()->where('company_id', $data['company_id'])->orderByDesc('effective_from')->get(),
            'business_calendars' => SalesCommissionBusinessCalendar::query()->where('company_id', $data['company_id'])->orderBy('code')->orderByDesc('effective_from')->get(),
            'business_calendar_dates' => SalesCommissionBusinessCalendarDate::query()
                ->whereIn('calendar_id', SalesCommissionBusinessCalendar::query()->where('company_id', $data['company_id'])->select('id'))
                ->orderBy('calendar_date')->get(),
            'references' => [
                'profiles' => DB::table('sales_profiles as profile')->join('staff', 'staff.id', '=', 'profile.staff_id')
                    ->where('profile.company_id', $data['company_id'])->where('profile.status', 'active')
                    ->where('profile.effective_from', '<=', now())
                    ->where(fn ($q) => $q->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                    ->where(fn ($q) => $q->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
                    ->whereNull('staff.deleted_at')
                    ->select(['profile.id', 'profile.staff_id', 'profile.sales_code', 'staff.code as staff_code', 'staff.staff_type'])
                    ->orderBy('profile.sales_code')->get(),
                'staff' => DB::table('staff')->where('company_id', $data['company_id'])
                    ->where(fn ($q) => $q->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))->whereNull('deleted_at')
                    ->select(['id', 'code', 'staff_type', 'employment_ended_at'])->orderBy('code')->get(),
                'staff_categories' => DB::table('staff')->where('company_id', $data['company_id'])
                    ->where(fn ($q) => $q->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))->whereNull('deleted_at')
                    ->whereNotNull('staff_type')->distinct()->orderBy('staff_type')->pluck('staff_type'),
            ],
        ]]);
    }

    public function storeFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'], 'commission_category' => ['required', Rule::in(['one_time', 'long_term'])],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        return response()->json(['status' => 'success', 'data' => SalesCommissionPlanFamily::create($data + ['status' => 'draft', 'created_by' => $request->user()->id])], 201);
    }

    public function approveFamily(Request $request, SalesCommissionPlanFamily $family): JsonResponse
    {
        $this->assertCompanyScope($request, $family->company_id);
        $family = $this->approveDraft($family, $request, 'plan family', fn () => null);
        return response()->json(['status' => 'success', 'data' => $family]);
    }

    public function storeVersion(Request $request, SalesCommissionPlanFamily $family): JsonResponse
    {
        $data = $request->validate([
            'formula_kind' => ['required', Rule::in(['percentage', 'fixed', 'tiered_percentage'])],
            'percentage_rate' => ['nullable', 'required_if:formula_kind,percentage', 'numeric', 'gt:0', 'max:100'],
            'fixed_amount_lkr' => ['nullable', 'required_if:formula_kind,fixed', 'numeric', 'gt:0'],
            'rounding_mode' => ['required', Rule::in(['half_up', 'half_down', 'half_even', 'half_odd'])],
            'rounding_scale' => ['required', 'integer', 'min:0', 'max:4'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'tiers' => ['nullable', 'required_if:formula_kind,tiered_percentage', 'array', 'min:1', 'max:100'],
            'tiers.*.minimum_lkr' => ['required', 'numeric', 'min:0'], 'tiers.*.maximum_lkr' => ['nullable', 'numeric', 'gt:0'],
            'tiers.*.minimum_inclusive' => ['required', 'boolean'], 'tiers.*.maximum_inclusive' => ['required', 'boolean'],
            'tiers.*.percentage_rate' => ['required', 'numeric', 'gt:0', 'max:100'],
        ]);
        $this->assertCompanyScope($request, $family->company_id);
        abort_unless($family->status === 'approved', 422, 'Approve the plan family before authoring versions.');
        if ($data['formula_kind'] === 'tiered_percentage') {
            $this->validateTiers($data['tiers']);
        }
        $version = DB::transaction(function () use ($request, $family, $data) {
            SalesCommissionPlanFamily::query()->whereKey($family->id)->lockForUpdate()->firstOrFail();
            $number = (int) SalesCommissionPlanVersion::query()->where('plan_family_id', $family->id)->lockForUpdate()->max('version') + 1;
            $version = SalesCommissionPlanVersion::create([
                ...collect($data)->except('tiers')->all(), 'plan_family_id' => $family->id,
                'version' => $number, 'eligible_basis' => 'full_eligible_receipt_lkr', 'status' => 'draft', 'created_by' => $request->user()->id,
            ]);
            foreach ($data['tiers'] ?? [] as $index => $tier) {
                SalesCommissionPlanTier::create($tier + ['plan_version_id' => $version->id, 'sequence' => $index + 1]);
            }
            return $version;
        });
        return response()->json(['status' => 'success', 'data' => $version], 201);
    }

    public function approveVersion(Request $request, SalesCommissionPlanVersion $version): JsonResponse
    {
        $family = SalesCommissionPlanFamily::query()->findOrFail($version->plan_family_id);
        $this->assertCompanyScope($request, $family->company_id);
        $version = $this->approveDraft($version, $request, 'plan version', function ($locked) {
            SalesCommissionPlanFamily::query()->whereKey($locked->plan_family_id)->lockForUpdate()->firstOrFail();
            $overlap = SalesCommissionPlanVersion::query()->where('plan_family_id', $locked->plan_family_id)->where('status', 'approved')
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved version overlaps this effective interval.');
        });
        return response()->json(['status' => 'success', 'data' => $version]);
    }

    public function preview(Request $request, SalesCommissionPlanVersion $version): JsonResponse
    {
        $data = $request->validate(['eligible_lkr_amount' => ['required', 'numeric', 'gt:0']]);
        $family = SalesCommissionPlanFamily::query()->findOrFail($version->plan_family_id);
        $this->assertCompanyScope($request, $family->company_id);
        $basis = (float) $data['eligible_lkr_amount'];
        $tier = null; $rate = null; $amount = null;
        if ($version->formula_kind === 'percentage') {
            $rate = (float) $version->percentage_rate; $amount = $basis * $rate / 100;
        } elseif ($version->formula_kind === 'fixed') {
            $amount = (float) $version->fixed_amount_lkr;
        } else {
            $matches = SalesCommissionPlanTier::query()->where('plan_version_id', $version->id)->orderBy('sequence')->get()
                ->filter(fn ($candidate) => $this->tierMatches($candidate, $basis))->values();
            abort_unless($matches->count() === 1, 422, 'Preview amount must match exactly one tier.');
            $tier = $matches->first(); $rate = (float) $tier->percentage_rate; $amount = $basis * $rate / 100;
        }
        $mode = match ($version->rounding_mode) {'half_down' => PHP_ROUND_HALF_DOWN, 'half_even' => PHP_ROUND_HALF_EVEN, 'half_odd' => PHP_ROUND_HALF_ODD, default => PHP_ROUND_HALF_UP};
        return response()->json(['status' => 'success', 'data' => [
            'write_performed' => false, 'eligible_lkr_amount' => $basis, 'formula_kind' => $version->formula_kind,
            'tier_id' => $tier?->id, 'rate' => $rate, 'commission_amount_lkr' => round($amount, $version->rounding_scale, $mode),
        ]]);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'plan_family_id' => ['required', 'uuid', 'exists:sales_commission_plan_families,id'],
            'scope_type' => ['required', Rule::in(['company', 'staff_category', 'sales_profile', 'employee'])],
            'sales_profile_id' => ['nullable', 'required_if:scope_type,sales_profile', 'uuid', 'exists:sales_profiles,id'],
            'staff_id' => ['nullable', 'required_if:scope_type,employee', 'uuid', 'exists:staff,id'],
            'staff_category' => ['nullable', 'required_if:scope_type,staff_category', 'string', 'max:80'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        $this->validateAssignmentTargets($data);
        $assignment = SalesCommissionPlanAssignment::create($data + ['precedence' => $this->precedence($data['scope_type']), 'status' => 'draft', 'created_by' => $request->user()->id]);
        return response()->json(['status' => 'success', 'data' => $assignment], 201);
    }

    public function approveAssignment(Request $request, SalesCommissionPlanAssignment $assignment): JsonResponse
    {
        $this->assertCompanyScope($request, $assignment->company_id);
        $assignment = $this->approveDraft($assignment, $request, 'plan assignment', function ($locked) {
            DB::table('companies')->where('id', $locked->company_id)->lockForUpdate()->first();
            $family = SalesCommissionPlanFamily::query()->findOrFail($locked->plan_family_id);
            abort_unless($family->status === 'approved', 422, 'The assigned family is not approved.');
            $overlap = SalesCommissionPlanAssignment::query()->where('company_id', $locked->company_id)
                ->where('scope_type', $locked->scope_type)->where('status', 'approved')->where('id', '!=', $locked->id)
                ->where('sales_profile_id', $locked->sales_profile_id)->where('staff_id', $locked->staff_id)
                ->where('staff_category', $locked->staff_category)
                ->whereIn('plan_family_id', SalesCommissionPlanFamily::query()->where('commission_category', $family->commission_category)->select('id'))
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved assignment already overlaps this scope/category interval.');
        });
        return response()->json(['status' => 'success', 'data' => $assignment]);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'percentage_rate' => ['required', 'numeric', 'gt:0', 'max:100'], 'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        abort_unless($this->activeStaffExists($data['staff_id'], $data['company_id']), 422, 'Staff must be active in this legal entity.');
        return response()->json(['status' => 'success', 'data' => SalesCommissionStaffOverride::create($data + ['status' => 'draft', 'created_by' => $request->user()->id])], 201);
    }

    public function approveOverride(Request $request, SalesCommissionStaffOverride $override): JsonResponse
    {
        $this->assertCompanyScope($request, $override->company_id);
        $override = $this->approveDraft($override, $request, 'Staff override', function ($locked) {
            Staff::query()->whereKey($locked->staff_id)->lockForUpdate()->firstOrFail();
            $overlap = SalesCommissionStaffOverride::query()->where('staff_id', $locked->staff_id)->where('status', 'approved')
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved Staff override overlaps this interval.');
        });
        return response()->json(['status' => 'success', 'data' => $override]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'code' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'timezone'], 'business_calendar_id' => ['required', 'uuid', 'exists:sales_commission_business_calendars,id'],
            'earning_period_rule' => ['required', Rule::in(['calendar_month', 'previous_cutoff_to_cutoff'])],
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:28'], 'finalization_day' => ['required', 'integer', 'min:1', 'max:28'],
            'approval_deadline_day' => ['required', 'integer', 'min:1', 'max:28'], 'settlement_day' => ['required', 'integer', 'min:1', 'max:28'],
            'holiday_rule' => ['required', Rule::in(['previous_business_day', 'next_business_day', 'no_movement'])],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        $calendar = SalesCommissionBusinessCalendar::query()->findOrFail($data['business_calendar_id']);
        abort_unless($calendar->company_id === $data['company_id'], 422, 'Business calendar belongs to another legal entity.');
        abort_unless($calendar->timezone === $data['timezone'], 422, 'Cycle and business calendar timezones must match.');
        $cycle = DB::transaction(function () use ($request, $data) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $version = (int) SalesCommissionCycleVersion::query()->where('company_id', $data['company_id'])->where('code', $data['code'])->lockForUpdate()->max('version') + 1;
            return SalesCommissionCycleVersion::create($data + ['version' => $version, 'payout_currency' => 'LKR', 'status' => 'draft', 'created_by' => $request->user()->id]);
        });
        return response()->json(['status' => 'success', 'data' => $cycle], 201);
    }

    public function approveCycle(Request $request, SalesCommissionCycleVersion $cycle): JsonResponse
    {
        $this->assertCompanyScope($request, $cycle->company_id);
        $cycle = $this->approveDraft($cycle, $request, 'commission cycle', function ($locked) {
            DB::table('companies')->where('id', $locked->company_id)->lockForUpdate()->first();
            $calendar = SalesCommissionBusinessCalendar::query()->whereKey($locked->business_calendar_id)->lockForUpdate()->first();
            abort_unless($calendar && $calendar->company_id === $locked->company_id && $calendar->status === 'approved', 422,
                'The cycle business calendar must be approved in the same legal entity.');
            abort_unless($calendar->timezone === $locked->timezone, 422, 'Cycle and business calendar timezones must match.');
            abort_if($calendar->effective_from->gt($locked->effective_from)
                || ($calendar->effective_until && (! $locked->effective_until || $calendar->effective_until->lt($locked->effective_until))), 422,
                'The business calendar effective interval must cover the complete cycle interval.');
            $overlap = SalesCommissionCycleVersion::query()->where('company_id', $locked->company_id)
                ->where('code', $locked->code)->where('status', 'approved')->where('id', '!=', $locked->id)
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved cycle version overlaps this interval.');
        });
        return response()->json(['status' => 'success', 'data' => $cycle]);
    }

    public function storeBusinessCalendar(Request $request): JsonResponse
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'], 'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'], 'timezone' => ['required', 'timezone'],
            'weekly_working_days' => ['required', 'array', 'min:1', 'max:7'],
            'weekly_working_days.*' => ['required', Rule::in($days), 'distinct'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        return response()->json(['status' => 'success', 'data' => SalesCommissionBusinessCalendar::create(
            $data + ['status' => 'draft', 'created_by' => $request->user()->id]
        )], 201);
    }

    public function storeBusinessCalendarDate(Request $request, SalesCommissionBusinessCalendar $calendar): JsonResponse
    {
        $this->assertCompanyScope($request, $calendar->company_id);
        abort_unless($calendar->status === 'draft', 409, 'Approved business calendars are immutable; create a new effective version.');
        $data = $request->validate([
            'calendar_date' => ['required', 'date', 'after_or_equal:'.$calendar->effective_from->toDateString()],
            'day_type' => ['required', Rule::in(['working_day', 'holiday'])],
            'name' => ['required', 'string', 'max:160'],
        ]);
        if ($calendar->effective_until) {
            abort_if($data['calendar_date'] >= $calendar->effective_until->toDateString(), 422,
                'Calendar exception must fall inside the calendar effective interval.');
        }
        abort_if(SalesCommissionBusinessCalendarDate::query()->where('calendar_id', $calendar->id)
            ->whereDate('calendar_date', $data['calendar_date'])->exists(), 422, 'A calendar exception already exists for this date.');
        return response()->json(['status' => 'success', 'data' => SalesCommissionBusinessCalendarDate::create(
            $data + ['calendar_id' => $calendar->id, 'created_by' => $request->user()->id]
        )], 201);
    }

    public function approveBusinessCalendar(Request $request, SalesCommissionBusinessCalendar $calendar): JsonResponse
    {
        $this->assertCompanyScope($request, $calendar->company_id);
        $calendar = $this->approveDraft($calendar, $request, 'business calendar', function ($locked) {
            DB::table('companies')->where('id', $locked->company_id)->lockForUpdate()->first();
            $overlap = SalesCommissionBusinessCalendar::query()->where('company_id', $locked->company_id)
                ->where('code', $locked->code)->where('status', 'approved')->where('id', '!=', $locked->id)
                ->whereDate('effective_from', '<', $locked->effective_until?->toDateString() ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $locked->effective_from))
                ->exists();
            abort_if($overlap, 422, 'An approved business-calendar version overlaps this interval.');
        });
        return response()->json(['status' => 'success', 'data' => $calendar]);
    }

    public function destroyBusinessCalendarDate(Request $request, SalesCommissionBusinessCalendarDate $calendarDate): JsonResponse
    {
        $calendar = SalesCommissionBusinessCalendar::query()->findOrFail($calendarDate->calendar_id);
        $this->assertCompanyScope($request, $calendar->company_id);
        abort_unless($calendar->status === 'draft', 409, 'Approved business-calendar evidence is immutable.');
        $calendarDate->delete();
        return response()->json(['status' => 'success', 'message' => 'Draft calendar exception removed.']);
    }

    public function storeCycleAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'cycle_version_id' => ['required', 'uuid', 'exists:sales_commission_cycle_versions,id'],
            'scope_type' => ['required', Rule::in(['company', 'staff_category', 'sales_profile', 'employee'])],
            'sales_profile_id' => ['nullable', 'required_if:scope_type,sales_profile', 'uuid', 'exists:sales_profiles,id'],
            'staff_id' => ['nullable', 'required_if:scope_type,employee', 'uuid', 'exists:staff,id'],
            'staff_category' => ['nullable', 'required_if:scope_type,staff_category', 'string', 'max:80'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        $this->validateScopedTarget($data);
        $cycle = SalesCommissionCycleVersion::query()->findOrFail($data['cycle_version_id']);
        abort_unless($cycle->company_id === $data['company_id'], 422, 'Cycle belongs to another legal entity.');
        $assignment = SalesCommissionCycleAssignment::create($data + ['precedence' => $this->precedence($data['scope_type']), 'status' => 'draft', 'created_by' => $request->user()->id]);
        return response()->json(['status' => 'success', 'data' => $assignment], 201);
    }

    public function approveCycleAssignment(Request $request, SalesCommissionCycleAssignment $assignment): JsonResponse
    {
        $this->assertCompanyScope($request, $assignment->company_id);
        $assignment = $this->approveDraft($assignment, $request, 'cycle assignment', function ($locked) {
            DB::table('companies')->where('id', $locked->company_id)->lockForUpdate()->first();
            $cycle = SalesCommissionCycleVersion::query()->findOrFail($locked->cycle_version_id);
            abort_unless($cycle->status === 'approved', 422, 'The assigned cycle version is not approved.');
            $overlap = SalesCommissionCycleAssignment::query()->where('company_id', $locked->company_id)
                ->where('scope_type', $locked->scope_type)->where('status', 'approved')->where('id', '!=', $locked->id)
                ->where('sales_profile_id', $locked->sales_profile_id)->where('staff_id', $locked->staff_id)
                ->where('staff_category', $locked->staff_category)
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved cycle assignment overlaps this scope interval.');
        });
        return response()->json(['status' => 'success', 'data' => $assignment]);
    }

    private function validateTiers(array $tiers): void
    {
        usort($tiers, fn ($a, $b) => (float) $a['minimum_lkr'] <=> (float) $b['minimum_lkr']);
        if ((float) $tiers[0]['minimum_lkr'] !== 0.0 || ! $tiers[0]['minimum_inclusive']) {
            throw ValidationException::withMessages(['tiers' => ['Approved tiers must begin inclusively at LKR 0.']]);
        }
        foreach ($tiers as $index => $tier) {
            $last = $index === count($tiers) - 1;
            if ($tier['maximum_lkr'] !== null && (float) $tier['maximum_lkr'] <= (float) $tier['minimum_lkr']) {
                throw ValidationException::withMessages(['tiers' => ['Each tier maximum must be greater than its minimum.']]);
            }
            if ($last && $tier['maximum_lkr'] !== null) throw ValidationException::withMessages(['tiers' => ['The last tier must have no maximum.']]);
            if (! $last) {
                $next = $tiers[$index + 1];
                if ((float) $tier['maximum_lkr'] !== (float) $next['minimum_lkr']
                    || ((bool) $tier['maximum_inclusive'] === (bool) $next['minimum_inclusive'])) {
                    throw ValidationException::withMessages(['tiers' => ['Tier boundaries must be continuous and belong to exactly one adjacent tier.']]);
                }
            }
        }
    }

    private function validateAssignmentTargets(array $data): void
    {
        $family = SalesCommissionPlanFamily::query()->findOrFail($data['plan_family_id']);
        abort_unless($family->company_id === $data['company_id'], 422, 'Plan family belongs to another legal entity.');
        $this->validateScopedTarget($data);
    }

    private function validateScopedTarget(array $data): void
    {
        $expected = [
            'company' => [],
            'staff_category' => ['staff_category'],
            'sales_profile' => ['sales_profile_id'],
            'employee' => ['staff_id'],
        ][$data['scope_type']];
        foreach (['staff_category', 'sales_profile_id', 'staff_id'] as $field) {
            $present = array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '';
            abort_if($present !== in_array($field, $expected, true), 422, 'Provide only the target that matches the selected scope.');
        }
        if (! empty($data['sales_profile_id'])) {
            abort_unless(SalesProfile::query()->whereKey($data['sales_profile_id'])->where('company_id', $data['company_id'])->activeAt(now())->exists(), 422, 'Sales Profile must be active in this legal entity.');
        }
        if (! empty($data['staff_id'])) {
            abort_unless($this->activeStaffExists($data['staff_id'], $data['company_id']), 422, 'Staff must be active in this legal entity.');
        }
        if (! empty($data['staff_category'])) {
            abort_unless(Staff::query()->where('company_id', $data['company_id'])->where('staff_type', $data['staff_category'])
                ->where(fn ($q) => $q->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))->exists(), 422, 'Staff category has no active Staff in this legal entity.');
        }
    }

    private function activeStaffExists(string $staffId, string $companyId): bool
    {
        return Staff::query()->whereKey($staffId)->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))->exists();
    }

    private function approveDraft($record, Request $request, string $label, callable $validate)
    {
        return DB::transaction(function () use ($record, $request, $label, $validate) {
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 422, "Only a draft {$label} can be approved.");
            abort_if($locked->created_by === $request->user()->id, 409, ucfirst($label).' creator cannot approve the same record.');
            $validate($locked);
            $locked->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            return $locked->refresh();
        });
    }

    private function precedence(string $scope): int { return ['company' => 100, 'staff_category' => 200, 'sales_profile' => 300, 'employee' => 400][$scope]; }
    private function tierMatches($tier, float $basis): bool
    {
        $min = (float) $tier->minimum_lkr; $max = $tier->maximum_lkr !== null ? (float) $tier->maximum_lkr : null;
        return ($tier->minimum_inclusive ? $basis >= $min : $basis > $min)
            && ($max === null || ($tier->maximum_inclusive ? $basis <= $max : $basis < $max));
    }
    private function assertCompanyScope(Request $request, string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        abort_unless($request->user()->can('sales.commission-config.manage-all')
            || in_array($companyId, $this->actorCompanyIds($request) ?? [], true), 403, 'Commission configuration is outside your legal entity.');
    }

    /** @return array<int, string>|null */
    private function actorCompanyIds(Request $request): ?array
    {
        if ($request->user()->can('sales.commission-config.manage-all')) return null;
        return Staff::query()->where('user_id', $request->user()->id)->whereNull('deleted_at')
            ->where(fn ($staff) => $staff->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->whereNotNull('company_id')->pluck('company_id')->unique()->values()->all();
    }
}
