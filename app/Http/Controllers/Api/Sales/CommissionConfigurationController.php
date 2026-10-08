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
use App\Services\SingleCompanyScope;
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
        $defaultCompanyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if ($defaultCompanyId && $companyIds !== null && ! in_array($defaultCompanyId, $companyIds, true)) $defaultCompanyId = null;
        $query = DB::table('companies')->whereNull('deleted_at')
            ->when($companyIds !== null, fn ($company) => $company->whereIn('id', $companyIds));
        if (! empty($data['selected_id'])) $query->where('id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($company) => $company->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city', 'is_active', 'is_default'])->orderByDesc('is_active')->orderByDesc('is_default')->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => ['value' => (string) $company->id, 'label' => $company->name,
            'metadata' => array_filter(['city' => $company->city, 'availability' => $company->is_active ? null : 'Inactive']) + ['is_default' => (bool) $company->is_default],
            'status' => $company->is_active ? 'active' : 'inactive']);
        return response()->json(['status' => 'success', 'data' => $rows, 'default_company_id' => $defaultCompanyId]);
    }

    public function referenceOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'record_type' => ['required', Rule::in(['sales_profile', 'employee', 'plan_family', 'cycle_version', 'approved_calendar', 'draft_calendar'])],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        if ($data['record_type'] === 'plan_family') {
            $query = SalesCommissionPlanFamily::query()->where('company_id', $data['company_id'])->where('status', 'approved');
            if (! empty($data['selected_id'])) $query->whereKey($data['selected_id']);
            elseif (! empty($data['search'])) {
                $term = '%' . addcslashes($data['search'], '%_\\') . '%';
                $query->where(fn ($match) => $match->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term)->orWhere('commission_category', 'like', $term));
            }
            $rows = $query->orderBy('code')->orderBy('id')->paginate($data['per_page'] ?? 25);
            $rows->getCollection()->transform(fn ($family) => [
                'value' => (string) $family->id, 'label' => $family->code . ' · ' . $family->name,
                'metadata' => ['category' => $family->commission_category], 'status' => $family->status,
            ]);
            return response()->json(['status' => 'success', 'data' => $rows]);
        }
        if ($data['record_type'] === 'cycle_version') {
            $query = SalesCommissionCycleVersion::query()->where('company_id', $data['company_id'])->where('status', 'approved');
            if (! empty($data['selected_id'])) $query->whereKey($data['selected_id']);
            elseif (! empty($data['search'])) {
                $term = '%' . addcslashes($data['search'], '%_\\') . '%';
                $query->where(fn ($match) => $match->where('code', 'like', $term)->orWhere('timezone', 'like', $term));
            }
            $rows = $query->orderBy('code')->orderByDesc('version')->orderBy('id')->paginate($data['per_page'] ?? 25);
            $rows->getCollection()->transform(fn ($cycle) => [
                'value' => (string) $cycle->id, 'label' => $cycle->code . ' v' . $cycle->version,
                'metadata' => ['timezone' => $cycle->timezone, 'effective_from' => (string) $cycle->effective_from], 'status' => $cycle->status,
            ]);
            return response()->json(['status' => 'success', 'data' => $rows]);
        }
        if (in_array($data['record_type'], ['approved_calendar', 'draft_calendar'], true)) {
            $status = $data['record_type'] === 'approved_calendar' ? 'approved' : 'draft';
            $query = SalesCommissionBusinessCalendar::query()->where('company_id', $data['company_id'])->where('status', $status);
            if (! empty($data['selected_id'])) $query->whereKey($data['selected_id']);
            elseif (! empty($data['search'])) {
                $term = '%' . addcslashes($data['search'], '%_\\') . '%';
                $query->where(fn ($match) => $match->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term)->orWhere('timezone', 'like', $term));
            }
            $rows = $query->orderBy('code')->orderByDesc('effective_from')->orderBy('id')->paginate($data['per_page'] ?? 25);
            $rows->getCollection()->transform(fn ($calendar) => [
                'value' => (string) $calendar->id, 'label' => $calendar->code . ' · ' . $calendar->timezone,
                'metadata' => ['name' => $calendar->name, 'effective_from' => (string) $calendar->effective_from], 'status' => $calendar->status,
            ]);
            return response()->json(['status' => 'success', 'data' => $rows]);
        }
        $query = $data['record_type'] === 'sales_profile'
            ? SalesProfile::query()->with('staff.user')->where('company_id', $data['company_id'])->activeAt(now())
                ->whereHas('staff', fn ($staff) => $staff->whereNull('deleted_at')
                    ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
            : Staff::query()->with('user:id,first_name,last_name')->where('company_id', $data['company_id'])
                ->whereNull('deleted_at')->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()));
        if (! empty($data['selected_id'])) $query->whereKey($data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(function ($match) use ($term, $data) {
                $match->when($data['record_type'] === 'sales_profile', fn ($profile) => $profile->where('sales_code', 'like', $term))
                    ->when($data['record_type'] === 'employee', fn ($staff) => $staff->where('code', 'like', $term))
                    ->orWhereHas($data['record_type'] === 'sales_profile' ? 'staff.user' : 'user', fn ($user) => $user
                        ->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term));
            });
        }
        $rows = $query->orderBy($data['record_type'] === 'sales_profile' ? 'sales_code' : 'code')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(function ($row) use ($data) {
            $staff = $data['record_type'] === 'sales_profile' ? $row->staff : $row;
            $name = trim((string) ($staff?->user?->first_name . ' ' . $staff?->user?->last_name));
            return ['value' => (string) $row->id,
                'label' => $data['record_type'] === 'sales_profile' ? $row->sales_code : ($name ?: $staff->code),
                'metadata' => ['staff_code' => $staff?->code, 'staff_category' => $staff?->staff_type], 'status' => 'active'];
        });
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function versionOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        $query = DB::table('sales_commission_plan_versions as version')
            ->join('sales_commission_plan_families as family', 'family.id', '=', 'version.plan_family_id')
            ->where('family.company_id', $data['company_id']);
        if (! empty($data['selected_id'])) $query->where('version.id', $data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($match) => $match->where('family.code', 'like', $term)
                ->orWhere('family.name', 'like', $term)->orWhere('version.formula_kind', 'like', $term));
        }
        $rows = $query->select([
            'version.id', 'version.version', 'version.formula_kind', 'version.effective_from', 'version.status',
            'family.code as family_code', 'family.name as family_name',
        ])->orderBy('family.code')->orderByDesc('version.version')->orderBy('version.id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($row) => [
            'value' => (string) $row->id,
            'label' => $row->family_code . ' v' . $row->version . ' · ' . str_replace('_', ' ', $row->formula_kind),
            'metadata' => ['family' => $row->family_name, 'effective_from' => (string) $row->effective_from],
            'status' => $row->status,
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id']]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        $familyIds = SalesCommissionPlanFamily::query()->where('company_id', $data['company_id'])->select('id');
        $versionIds = SalesCommissionPlanVersion::query()->whereIn('plan_family_id', $familyIds)->select('id');
        $assignments = SalesCommissionPlanAssignment::query()->where('company_id', $data['company_id'])->orderByDesc('effective_from')->get();
        $overrides = SalesCommissionStaffOverride::query()->where('company_id', $data['company_id'])->orderByDesc('effective_from')->get();
        $cycleAssignments = SalesCommissionCycleAssignment::query()->where('company_id', $data['company_id'])->orderByDesc('effective_from')->get();
        $profiles = SalesProfile::query()->with('staff')->where('company_id', $data['company_id'])
            ->whereIn('id', $assignments->concat($cycleAssignments)->pluck('sales_profile_id')->filter()->unique())->get()->keyBy('id');
        $staff = Staff::query()->where('company_id', $data['company_id'])->whereNull('deleted_at')
            ->whereIn('id', $assignments->concat($cycleAssignments)->pluck('staff_id')->merge($overrides->pluck('staff_id'))->filter()->unique())->get()->keyBy('id');
        foreach ($assignments->concat($cycleAssignments)->concat($overrides) as $row) {
            $row->setAttribute('target_type', $row->sales_profile_id ? 'sales_profile' : ($row->staff_id ? 'employee' : $row->scope_type));
            $row->setAttribute('target_label', $row->sales_profile_id
                ? (($profile = $profiles->get($row->sales_profile_id)) ? trim($profile->sales_code . ' · ' . ($profile->staff?->code ?? '')) : null)
                : (($person = $staff->get($row->staff_id)) ? trim($person->code . ' · ' . $person->staff_type) : null));
        }
        $families = SalesCommissionPlanFamily::query()->where('company_id', $data['company_id'])->orderBy('code')->get();
        $versions = SalesCommissionPlanVersion::query()->whereIn('plan_family_id', $familyIds)->orderByDesc('effective_from')->get();
        $tiers = SalesCommissionPlanTier::query()->whereIn('plan_version_id', $versionIds)->orderBy('sequence')->get();
        $cycles = SalesCommissionCycleVersion::query()->where('company_id', $data['company_id'])->orderBy('code')->orderByDesc('version')->get();
        $calendars = SalesCommissionBusinessCalendar::query()->where('company_id', $data['company_id'])->orderBy('code')->orderByDesc('effective_from')->get();
        $calendarDates = SalesCommissionBusinessCalendarDate::query()
            ->whereIn('calendar_id', SalesCommissionBusinessCalendar::query()->where('company_id', $data['company_id'])->select('id'))
            ->orderBy('calendar_date')->get();
        $hideInternal = static fn ($rows, array $extra = []) => $rows->each(
            fn ($row) => $row->makeHidden(array_merge(['company_id', 'created_by', 'updated_by', 'approved_by', 'approved_at', 'created_at', 'updated_at'], $extra)),
        );
        $hideInternal($families);
        $hideInternal($versions);
        $hideInternal($tiers);
        $hideInternal($assignments, ['staff_id', 'sales_profile_id']);
        $overrideHidden = ['staff_id'];
        if (! $request->user()->can('sales.commission-config.approve')) $overrideHidden[] = 'reason';
        $hideInternal($overrides, $overrideHidden);
        $hideInternal($cycles);
        $hideInternal($cycleAssignments, ['staff_id', 'sales_profile_id']);
        $hideInternal($calendars);
        $hideInternal($calendarDates);
        return response()->json(['status' => 'success', 'data' => [
            'families' => $families,
            'versions' => $versions,
            'tiers' => $tiers,
            'assignments' => $assignments,
            'overrides' => $overrides,
            'cycles' => $cycles,
            'cycle_assignments' => $cycleAssignments,
            'business_calendars' => $calendars,
            'business_calendar_dates' => $calendarDates,
            'references' => [
                'staff_categories' => DB::table('staff')->where('company_id', $data['company_id'])
                    ->where(fn ($q) => $q->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))->whereNull('deleted_at')
                    ->whereNotNull('staff_type')->distinct()->orderBy('staff_type')->pluck('staff_type'),
            ],
        ]]);
    }

    public function storeFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'], 'commission_category' => ['required', Rule::in(['one_time', 'long_term'])],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        return $this->confirmation(SalesCommissionPlanFamily::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]), 201);
    }

    public function approveFamily(Request $request, SalesCommissionPlanFamily $family): JsonResponse
    {
        $this->assertCompanyScope($request, $family->company_id);
        $family = $this->approveDraft($family, $request, $family->company_id, 'plan family', fn () => null);
        return $this->confirmation($family);
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
        return $this->confirmation($version, 201);
    }

    public function approveVersion(Request $request, SalesCommissionPlanVersion $version): JsonResponse
    {
        $family = SalesCommissionPlanFamily::query()->findOrFail($version->plan_family_id);
        $this->assertCompanyScope($request, $family->company_id);
        $version = $this->approveDraft($version, $request, $family->company_id, 'plan version', function ($locked) {
            SalesCommissionPlanFamily::query()->whereKey($locked->plan_family_id)->lockForUpdate()->firstOrFail();
            $overlap = SalesCommissionPlanVersion::query()->where('plan_family_id', $locked->plan_family_id)->where('status', 'approved')
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved version overlaps this effective interval.');
        });
        return $this->confirmation($version);
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
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'plan_family_id' => ['required', 'uuid', 'exists:sales_commission_plan_families,id'],
            'scope_type' => ['required', Rule::in(['company', 'staff_category', 'sales_profile', 'employee'])],
            'sales_profile_id' => ['nullable', 'required_if:scope_type,sales_profile', 'uuid', 'exists:sales_profiles,id'],
            'staff_id' => ['nullable', 'required_if:scope_type,employee', 'uuid', 'exists:staff,id'],
            'staff_category' => ['nullable', 'required_if:scope_type,staff_category', 'string', 'max:80'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        $this->validateAssignmentTargets($data);
        $assignment = SalesCommissionPlanAssignment::create($data + ['precedence' => $this->precedence($data['scope_type']), 'status' => 'draft', 'created_by' => $request->user()->id]);
        return $this->confirmation($assignment, 201);
    }

    public function approveAssignment(Request $request, SalesCommissionPlanAssignment $assignment): JsonResponse
    {
        $this->assertCompanyScope($request, $assignment->company_id);
        $assignment = $this->approveDraft($assignment, $request, $assignment->company_id, 'plan assignment', function ($locked) {
            $this->validateScopedTarget($locked->only(['company_id', 'scope_type', 'staff_category', 'sales_profile_id', 'staff_id']));
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
        return $this->confirmation($assignment);
    }

    public function storeOverride(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'percentage_rate' => ['required', 'numeric', 'gt:0', 'max:100'], 'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:2000'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        abort_unless($this->activeStaffExists($data['staff_id'], $data['company_id']), 422, 'Staff must be active in this legal entity.');
        return $this->confirmation(SalesCommissionStaffOverride::create($data + ['status' => 'draft', 'created_by' => $request->user()->id]), 201);
    }

    public function approveOverride(Request $request, SalesCommissionStaffOverride $override): JsonResponse
    {
        $this->assertCompanyScope($request, $override->company_id);
        $override = $this->approveDraft($override, $request, $override->company_id, 'Staff override', function ($locked) {
            Staff::query()->whereKey($locked->staff_id)->lockForUpdate()->firstOrFail();
            $overlap = SalesCommissionStaffOverride::query()->where('staff_id', $locked->staff_id)->where('status', 'approved')
                ->where('effective_from', '<', $locked->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $locked->effective_from))->exists();
            abort_if($overlap, 422, 'An approved Staff override overlaps this interval.');
        });
        return $this->confirmation($override);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'code' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'timezone'], 'business_calendar_id' => ['required', 'uuid', 'exists:sales_commission_business_calendars,id'],
            'earning_period_rule' => ['required', Rule::in(['calendar_month', 'previous_cutoff_to_cutoff'])],
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:28'], 'finalization_day' => ['required', 'integer', 'min:1', 'max:28'],
            'approval_deadline_day' => ['required', 'integer', 'min:1', 'max:28'], 'settlement_day' => ['required', 'integer', 'min:1', 'max:28'],
            'holiday_rule' => ['required', Rule::in(['previous_business_day', 'next_business_day', 'no_movement'])],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        $calendar = SalesCommissionBusinessCalendar::query()->findOrFail($data['business_calendar_id']);
        abort_unless($calendar->company_id === $data['company_id'], 422, 'Business calendar belongs to another legal entity.');
        abort_unless($calendar->timezone === $data['timezone'], 422, 'Cycle and business calendar timezones must match.');
        $cycle = DB::transaction(function () use ($request, $data) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->first();
            $version = (int) SalesCommissionCycleVersion::query()->where('company_id', $data['company_id'])->where('code', $data['code'])->lockForUpdate()->max('version') + 1;
            return SalesCommissionCycleVersion::create($data + ['version' => $version, 'payout_currency' => 'LKR', 'status' => 'draft', 'created_by' => $request->user()->id]);
        });
        return $this->confirmation($cycle, 201);
    }

    public function approveCycle(Request $request, SalesCommissionCycleVersion $cycle): JsonResponse
    {
        $this->assertCompanyScope($request, $cycle->company_id);
        $cycle = $this->approveDraft($cycle, $request, $cycle->company_id, 'commission cycle', function ($locked) {
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
        return $this->confirmation($cycle);
    }

    public function storeBusinessCalendar(Request $request): JsonResponse
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'], 'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'], 'timezone' => ['required', 'timezone'],
            'weekly_working_days' => ['required', 'array', 'min:1', 'max:7'],
            'weekly_working_days.*' => ['required', Rule::in($days), 'distinct'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        return $this->confirmation(SalesCommissionBusinessCalendar::create(
            $data + ['status' => 'draft', 'created_by' => $request->user()->id]
        ), 201);
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
        return $this->confirmation(SalesCommissionBusinessCalendarDate::create(
            $data + ['calendar_id' => $calendar->id, 'created_by' => $request->user()->id]
        ), 201);
    }

    public function approveBusinessCalendar(Request $request, SalesCommissionBusinessCalendar $calendar): JsonResponse
    {
        $this->assertCompanyScope($request, $calendar->company_id);
        $calendar = $this->approveDraft($calendar, $request, $calendar->company_id, 'business calendar', function ($locked) {
            $overlap = SalesCommissionBusinessCalendar::query()->where('company_id', $locked->company_id)
                ->where('code', $locked->code)->where('status', 'approved')->where('id', '!=', $locked->id)
                ->whereDate('effective_from', '<', $locked->effective_until?->toDateString() ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $locked->effective_from))
                ->exists();
            abort_if($overlap, 422, 'An approved business-calendar version overlaps this interval.');
        });
        return $this->confirmation($calendar);
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
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'cycle_version_id' => ['required', 'uuid', 'exists:sales_commission_cycle_versions,id'],
            'scope_type' => ['required', Rule::in(['company', 'staff_category', 'sales_profile', 'employee'])],
            'sales_profile_id' => ['nullable', 'required_if:scope_type,sales_profile', 'uuid', 'exists:sales_profiles,id'],
            'staff_id' => ['nullable', 'required_if:scope_type,employee', 'uuid', 'exists:staff,id'],
            'staff_category' => ['nullable', 'required_if:scope_type,staff_category', 'string', 'max:80'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $data['company_id'] = $this->resolveCompanyId($data['company_id'] ?? null);
        $this->assertCompanyScope($request, $data['company_id']);
        $this->validateScopedTarget($data);
        $cycle = SalesCommissionCycleVersion::query()->findOrFail($data['cycle_version_id']);
        abort_unless($cycle->company_id === $data['company_id'], 422, 'Cycle belongs to another legal entity.');
        $assignment = SalesCommissionCycleAssignment::create($data + ['precedence' => $this->precedence($data['scope_type']), 'status' => 'draft', 'created_by' => $request->user()->id]);
        return $this->confirmation($assignment, 201);
    }

    public function approveCycleAssignment(Request $request, SalesCommissionCycleAssignment $assignment): JsonResponse
    {
        $this->assertCompanyScope($request, $assignment->company_id);
        $assignment = $this->approveDraft($assignment, $request, $assignment->company_id, 'cycle assignment', function ($locked) {
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
        return $this->confirmation($assignment);
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
            abort_unless(SalesProfile::query()->whereKey($data['sales_profile_id'])->where('company_id', $data['company_id'])->activeAt(now())
                ->whereHas('staff', fn ($staff) => $staff->whereNull('deleted_at')
                    ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now())))
                ->exists(), 422, 'Sales Profile and Staff must be active in this legal entity.');
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

    private function approveDraft($record, Request $request, string $companyId, string $label, callable $validate)
    {
        return DB::transaction(function () use ($record, $request, $companyId, $label, $validate) {
            $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 422, 'Select an active legal entity.');
            $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                abort_unless($locked->approved_by === $request->user()->id, 409, ucfirst($label).' was approved by another actor.');
                return $locked;
            }
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
    private function confirmation(object $row, int $httpStatus = 200): JsonResponse
    {
        $data = ['id' => (string) $row->id];
        foreach (['status', 'version'] as $field) {
            if (isset($row->{$field})) $data[$field] = $row->{$field};
        }
        return response()->json(['status' => 'success', 'data' => $data], $httpStatus);
    }
    private function assertCompanyScope(Request $request, string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        abort_unless($request->user()->can('sales.commission-config.manage-all')
            || in_array($companyId, $this->actorCompanyIds($request) ?? [], true), 403, 'Commission configuration is outside your legal entity.');
    }

    private function resolveCompanyId(?string $companyId): string
    {
        $resolved = $companyId ?: app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($resolved, 409, 'No active default legal entity is configured.');

        return (string) $resolved;
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
