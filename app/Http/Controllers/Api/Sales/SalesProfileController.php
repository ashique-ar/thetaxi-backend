<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesProfileExport;
use App\Models\Staff;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\Sales\SalesProfileExportService;
use App\Services\UserContextService;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesProfileController extends Controller
{
    public function __construct(
        private readonly UserContextService $contextService,
        private readonly SalesAccessScope $scope,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function me(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
        ]);
        $profile = $this->scope
            ->activeProfile($request->user(), $data['company_id'] ?? null)
            ->load('staff.user');

        return response()->json(['status' => 'success', 'data' => $this->payload($profile)]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'ended'])],
            'search' => ['nullable', 'string', 'max:100'],
            'manageable' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $manageableOnly = (bool) ($data['manageable'] ?? false);
        if ($manageableOnly && ! $this->scope->hasPermission($request->user(), 'sales.profiles.manage')) {
            $this->fail('SUBJECT_SCOPE_DENIED', 'Sales Profile management options are unavailable.', 403);
        }
        $allPermission = $manageableOnly ? 'sales.profiles.manage-all' : 'sales.profiles.view-all';
        $teamPermission = $manageableOnly ? 'sales.profiles.manage-team' : 'sales.profiles.view-team';
        $companyId = $data['company_id'] ?? null;
        if (! $companyId && ! $this->scope->hasPermission($request->user(), $allPermission)) {
            $this->fail('VALIDATION_FAILED', 'A legal entity is required for a scoped Sales Profile roster.', 422);
        }

        $profiles = SalesProfile::query()
            ->with('staff.user')
            ->when($companyId, fn (Builder $query, string $id) => $query->where('company_id', $id))
            ->when($data['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(trim((string) ($data['search'] ?? '')), function (Builder $query, string $search): void {
                $term = '%'.trim($search).'%';
                $query->where(function (Builder $match) use ($term): void {
                    $match->whereLike('sales_code', $term)
                        ->orWhereHas('staff', fn (Builder $staff) => $staff
                            ->whereLike('code', $term)
                            ->orWhereHas('user', fn (Builder $user) => $user
                                ->whereLike('first_name', $term)
                                ->orWhereLike('last_name', $term)));
                });
            });
        $profiles = $this->scope->scopeProfiles(
            $profiles,
            $request->user(),
            $allPermission,
            $teamPermission,
            $companyId,
        )->orderBy('sales_code')->orderBy('id')->paginate($request->integer('per_page', 25));
        $manageableIds = $this->scope->authorizedProfileIds(
            $request->user(),
            'sales.profiles.manage',
            'sales.profiles.manage-all',
            'sales.profiles.manage-team',
            $companyId,
        );

        return response()->json([
            'status' => 'success',
            'data' => $profiles->through(fn (SalesProfile $profile) => $this->payload(
                $profile,
                $manageableIds === null || in_array($profile->id, $manageableIds, true),
            )),
        ]);
    }

    public function export(Request $request, SalesProfileExportService $exports): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'ended'])],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);

        $query = SalesProfile::query()
            ->where('company_id', $data['company_id'])
            ->when($data['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status));
        $query = $this->scope->scopeProfiles(
            $query,
            $request->user(),
            'sales.profiles.view-all',
            'sales.profiles.view-team',
            $data['company_id'],
        );

        $export = $exports->generate(
            $query,
            $data['company_id'],
            $data['status'] ?? null,
            $this->scope->scopeType($request->user(), 'sales.profiles.view-all', 'sales.profiles.view-team'),
            $data['idempotency_key'],
            (string) $request->user()->id,
            $request->header('X-Correlation-ID'),
            $request->ip(),
        );

        return response()->json(['status' => 'success', 'data' => [
            'id' => $export->id,
            'row_count' => $export->row_count,
            'file_name' => $export->file_name,
            'file_checksum' => $export->file_checksum,
            'file_size' => $export->file_size,
            'generated_at' => $export->generated_at,
            'expires_at' => $export->expires_at,
        ]], 201);
    }

    public function downloadExport(
        Request $request,
        SalesProfileExport $export,
        SalesProfileExportService $exports,
    ): StreamedResponse {
        $request->merge(['reason' => $request->header('X-Download-Reason')]);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        if ($export->generated_by !== (string) $request->user()->id) {
            abort(404, 'Sales Profile export is unavailable.');
        }
        if (! $export->company_id || ! in_array($export->scope_type, ['self', 'team', 'all'], true)) {
            $this->fail('RECONCILIATION_BLOCK', 'The Sales Profile export has no verifiable frozen authorization scope.', 409);
        }
        if ($export->expires_at?->isPast()) {
            $this->fail('STATE_TRANSITION_INVALID', 'The Sales Profile export has expired.', 410);
        }

        $profileIds = collect($export->scope_profile_ids ?? [])->map(fn ($id) => (string) $id)->sort()->values()->all();
        $expectedScopeChecksum = $exports::scopeChecksum(
            (string) $export->company_id,
            (string) $export->scope_type,
            $profileIds,
        );
        if (! $export->scope_checksum || ! hash_equals($export->scope_checksum, $expectedScopeChecksum)) {
            $this->fail('RECONCILIATION_BLOCK', 'The frozen Sales Profile export scope could not be verified.', 409);
        }

        $currentIds = $this->scope->profileIds(
            $request->user(),
            'sales.profiles.view-all',
            'sales.profiles.view-team',
            $export->company_id,
        );
        if ($currentIds !== null && array_diff($profileIds, $currentIds) !== []) {
            abort(404, 'Sales Profile export is unavailable.');
        }
        if (! Storage::disk($export->disk)->exists($export->path)) {
            abort(404, 'Sales Profile export is unavailable.');
        }

        $content = Storage::disk($export->disk)->get($export->path);
        if (! hash_equals($export->file_checksum, hash('sha256', $content))) {
            $this->fail('RECONCILIATION_BLOCK', 'The Sales Profile export file checksum did not match.', 409);
        }

        DB::transaction(function () use ($export, $request, $data): void {
            $locked = SalesProfileExport::query()->lockForUpdate()->findOrFail($export->id);
            if ($locked->generated_by !== (string) $request->user()->id || $locked->expires_at?->isPast()) {
                abort(404, 'Sales Profile export is unavailable.');
            }
            $locked->update([
                'download_count' => $locked->download_count + 1,
                'last_downloaded_at' => now(),
                'last_downloaded_by' => $request->user()->id,
                'updated_user_id' => $request->user()->id,
            ]);
            DB::table('domain_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'domain' => 'sales',
                'company_id' => $locked->company_id,
                'subject_type' => 'sales_profile_export',
                'subject_id' => $locked->id,
                'event_type' => 'sales.profile_export.downloaded',
                'actor_user_id' => $request->user()->id,
                'actor_type' => 'user',
                'correlation_id' => $request->header('X-Correlation-ID'),
                'source_ip' => $request->ip(),
                'before_checksum' => $locked->file_checksum,
                'after_checksum' => $locked->file_checksum,
                'reason' => $data['reason'],
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $export->file_name,
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'reporting_currency' => strtoupper((string) $request->input('reporting_currency')),
        ]);
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'sales_code' => ['required', 'string', 'max:80'],
            'acquisition_eligible' => ['required', 'boolean'],
            'collection_eligible' => ['required', 'boolean'],
            'commission_eligible' => ['required', 'boolean'],
            'reporting_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        if (! $data['acquisition_eligible'] && ! $data['collection_eligible'] && ! $data['commission_eligible']) {
            $this->fail('VALIDATION_FAILED', 'A Sales Profile must have at least one explicit eligibility.', 422);
        }
        $salesStaffCategories = $this->salesStaffCategories($data['company_id']);
        if ($salesStaffCategories === []) {
            $this->fail('CONFIGURATION_MISSING', 'Approved Sales Staff categories are not configured.', 503);
        }
        abort_unless(DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.profiles.manage-all');

        $profile = DB::transaction(function () use ($data, $request, $salesStaffCategories) {
            DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            $staff = Staff::query()->lockForUpdate()->findOrFail($data['staff_id']);
            abort_unless($staff->company_id === $data['company_id'], 422, 'Staff and Sales Profile must belong to the same legal entity.');
            abort_unless($staff->user_id, 422, 'Sales self-service enrollment requires a linked User identity.');
            abort_unless($this->isSalesStaffCategory($staff->staff_type, $salesStaffCategories), 422,
                'Only Staff in an approved Sales category may be explicitly enrolled as a Sales Profile.');
            if (! $this->scope->hasPermission($request->user(), 'sales.profiles.manage-all')) {
                $permittedProfileIds = $this->scope->profileIds(
                    $request->user(),
                    'sales.profiles.manage-all',
                    'sales.profiles.manage-team',
                    $data['company_id'],
                ) ?? [];
                $permittedStaffIds = SalesProfile::query()
                    ->whereIn('id', $permittedProfileIds)
                    ->pluck('staff_id')
                    ->all();
                abort_unless(
                    in_array($staff->id, $permittedStaffIds, true),
                    403,
                    'The Staff member is outside your permitted Sales enrollment scope.',
                );
            }
            abort_unless(! $staff->employment_ended_at
                || (! empty($data['effective_until']) && $staff->employment_ended_at->gte($data['effective_until'])),
                422,
                'The Sales Profile interval cannot extend beyond the Staff employment end.',
            );

            $activeIdentityRows = Staff::query()
                ->where('user_id', $staff->user_id)
                ->where(fn (Builder $employment) => $employment
                    ->whereNull('employment_ended_at')
                    ->orWhere('employment_ended_at', '>', $data['effective_from']))
                ->lockForUpdate()
                ->get(['id']);
            abort_unless(
                $activeIdentityRows->count() === 1 && $activeIdentityRows->first()->id === $staff->id,
                422,
                'Sales Profile activation requires one unambiguous active Staff identity.',
            );

            $overlap = SalesProfile::query()
                ->where('staff_id', $data['staff_id'])
                ->where('company_id', $data['company_id'])
                ->where('effective_from', '<', $data['effective_until'] ?? '9999-12-31')
                ->where(fn (Builder $query) => $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $data['effective_from']))
                ->lockForUpdate()
                ->exists();
            abort_if($overlap, 422, 'An overlapping Sales Profile enrollment already exists.');

            $profile = SalesProfile::create($data + [
                'staff_category_snapshot' => trim((string) $staff->staff_type),
                'status' => 'active',
                'version' => 1,
                'created_user_id' => $request->user()->id,
            ]);
            DB::table('sales_profile_events')->insert([
                'id' => (string) Str::uuid(),
                'sales_profile_id' => $profile->id,
                'profile_version' => $profile->version,
                'event_type' => 'enrolled',
                'from_status' => null,
                'to_status' => 'active',
                'reason' => 'Sales Profile enrollment',
                'before_configuration' => null,
                'after_configuration' => CanonicalJson::encode($this->configurationSnapshot($profile)),
                'idempotency_key' => null,
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('domain_audit_events')->insert([
                'id' => (string) Str::uuid(),
                'domain' => 'sales',
                'company_id' => $profile->company_id,
                'subject_type' => 'sales_profile',
                'subject_id' => $profile->id,
                'event_type' => 'sales.profile.enrolled',
                'actor_user_id' => $request->user()->id,
                'actor_type' => 'user',
                'correlation_id' => $request->header('X-Correlation-ID'),
                'source_ip' => $request->ip(),
                'before_checksum' => null,
                'after_checksum' => hash('sha256', CanonicalJson::encode($this->configurationSnapshot($profile))),
                'reason' => 'Sales Profile enrollment',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $user = $staff->user()->firstOrFail();
            $context = $user->contexts()
                ->where('context_type', 'staff')
                ->where('context_id', $staff->id)
                ->where('is_active', true)
                ->first();
            if (! $context) {
                $this->fail('AUTH_CONTEXT_INVALID', 'The linked Staff identity has no active internal context.', 422);
            }
            if (! DB::table('roles')->where('name', 'salesperson')->exists()) {
                $this->fail('CONFIGURATION_MISSING', 'The Salesperson authorization role is not configured.', 503);
            }
            $this->contextService->assignRolesToContext($user, $context, ['salesperson']);
            if (! $context->roles()->where('name', 'salesperson')->exists()) {
                $this->fail('CONFIGURATION_MISSING', 'The Salesperson role could not be assigned to the Staff context.', 503);
            }

            return $profile;
        });

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($profile->load('staff.user')),
        ], 201);
    }

    public function updateConfiguration(Request $request, SalesProfile $profile): JsonResponse
    {
        $this->scope->assertProfile(
            $request->user(),
            $profile,
            'sales.profiles.manage-all',
            'sales.profiles.manage-team',
        );
        $request->merge([
            'reporting_currency' => strtoupper((string) $request->input('reporting_currency')),
        ]);
        $data = $request->validate([
            'acquisition_eligible' => ['required', 'boolean'],
            'collection_eligible' => ['required', 'boolean'],
            'commission_eligible' => ['required', 'boolean'],
            'reporting_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        if (! $data['acquisition_eligible'] && ! $data['collection_eligible'] && ! $data['commission_eligible']) {
            $this->fail('VALIDATION_FAILED', 'A Sales Profile must have at least one explicit eligibility.', 422);
        }

        $profile = DB::transaction(function () use ($profile, $data, $request) {
            $profile = SalesProfile::query()->lockForUpdate()->findOrFail($profile->id);
            if ($profile->version !== (int) $data['expected_version']) {
                $this->fail('CONCURRENCY_CONFLICT', 'The Sales Profile changed; reload before retrying.', 409);
            }

            $staff = Staff::query()->lockForUpdate()->findOrFail($profile->staff_id);
            $salesStaffCategories = $this->salesStaffCategories((string) $profile->company_id);
            abort_unless($salesStaffCategories !== [] && $this->isSalesStaffCategory($staff->staff_type, $salesStaffCategories), 422,
                'The linked Staff record must be in an approved Sales category before Profile configuration is approved.');
            $before = $this->configurationSnapshot($profile);
            $configuration = [
                'acquisition_eligible' => (bool) $data['acquisition_eligible'],
                'collection_eligible' => (bool) $data['collection_eligible'],
                'commission_eligible' => (bool) $data['commission_eligible'],
                'reporting_currency' => $data['reporting_currency'],
            ];
            $current = $profile->only(array_keys($configuration));
            $categoryReconciliationRequired = ! $profile->staff_category_snapshot;
            if ($current === $configuration && ! $categoryReconciliationRequired) {
                $this->fail('STATE_TRANSITION_INVALID', 'The requested Sales Profile configuration is unchanged.', 422);
            }
            if ($profile->collection_eligible && ! $configuration['collection_eligible']) {
                abort_if(DB::table('sales_booking_attributions')
                    ->where('collection_sales_profile_id', $profile->id)
                    ->where('status', 'active')
                    ->exists(), 422, 'Transfer the active collection portfolio before removing collection eligibility.');
            }

            $profile->update($configuration + [
                'staff_category_snapshot' => trim((string) $staff->staff_type),
                'version' => $profile->version + 1,
                'updated_user_id' => $request->user()->id,
            ]);
            $after = $this->configurationSnapshot($profile);
            DB::table('sales_profile_events')->insert([
                'id' => (string) Str::uuid(),
                'sales_profile_id' => $profile->id,
                'profile_version' => $profile->version,
                'event_type' => 'configuration_changed',
                'from_status' => $profile->status,
                'to_status' => $profile->status,
                'reason' => $data['reason'],
                'before_configuration' => CanonicalJson::encode($before),
                'after_configuration' => CanonicalJson::encode($after),
                'idempotency_key' => null,
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->auditConfigurationChange($profile, 'sales.profile.configuration_changed', $before, $after, $data['reason'], $request);

            return $profile;
        });

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($profile->load('staff.user')),
        ]);
    }

    public function administrationContext(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid', 'exists:companies,id']]);
        if (! empty($data['company_id'])) {
            abort_unless(DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
            $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.profiles.view-all');
        }
        $companyIds = $this->scope->companyIds($request->user(), 'sales.profiles.view-all');
        if (empty($data['company_id'])) {
            return response()->json(['status' => 'success', 'data' => [
                'sales_staff_categories_by_company' => [],
                'sales_staff_category_ready_by_company' => [], 'profile_export_ready_by_company' => [],
                'can_manage_profiles' => $this->scope->hasPermission($request->user(), 'sales.profiles.manage'),
                'can_export_profiles' => $this->scope->hasPermission($request->user(), 'sales.profiles.export'),
            ]]);
        }
        $companies = DB::table('companies')
            ->whereNull('deleted_at')
            ->when($data['company_id'] ?? null, fn ($query, $companyId) => $query->where('id', $companyId))
            ->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds))
            ->orderBy('name')
            ->get(['id', 'name']);
        $categoriesByCompany = $companies->mapWithKeys(fn ($company) => [
            $company->id => $this->salesStaffCategories((string) $company->id),
        ]);
        $exportReadyByCompany = $companies->mapWithKeys(fn ($company) => [
            $company->id => ($this->policySettings->profileExportRetentionDays((string) $company->id) ?? 0) > 0,
        ]);

        return response()->json(['status' => 'success', 'data' => [
            'sales_staff_categories_by_company' => $categoriesByCompany,
            'sales_staff_category_ready_by_company' => $categoriesByCompany->map(fn (array $rows) => $rows !== []),
            'profile_export_ready_by_company' => $exportReadyByCompany,
            'can_manage_profiles' => $this->scope->hasPermission($request->user(), 'sales.profiles.manage'),
            'can_export_profiles' => $this->scope->hasPermission($request->user(), 'sales.profiles.export'),
        ]]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyIds = $this->scope->companyIds($request->user(), 'sales.profiles.view-all');
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

    public function staffOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        abort_unless(DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->exists(), 422, 'Select an available legal entity.');
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.profiles.manage-all');
        $categories = $this->salesStaffCategories($data['company_id']);
        $query = Staff::query()->with('user:id,first_name,last_name')->where('company_id', $data['company_id'])
            ->whereNull('deleted_at')->whereNotNull('user_id')->whereIn('staff_type', $categories)
            ->where(fn (Builder $employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()));
        if (! $this->scope->hasPermission($request->user(), 'sales.profiles.manage-all')) {
            $profileIds = $this->scope->profileIds($request->user(), 'sales.profiles.manage-all', 'sales.profiles.manage-team', $data['company_id']) ?? [];
            $query->whereIn('id', SalesProfile::query()->whereIn('id', $profileIds)->select('staff_id'));
        }
        if (! empty($data['selected_id'])) $query->whereKey($data['selected_id']);
        elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn (Builder $staff) => $staff->where('code', 'like', $term)
                ->orWhereHas('user', fn (Builder $user) => $user->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)));
        }
        $rows = $query->orderBy('code')->orderBy('id')->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(function (Staff $staff): array {
            $name = trim((string) ($staff->user?->first_name.' '.$staff->user?->last_name));
            return ['value' => (string) $staff->id, 'label' => $name !== '' ? $name : 'Named Staff',
                'metadata' => ['staff_code' => $staff->code, 'staff_category' => $staff->staff_type], 'status' => 'active'];
        });
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function transition(Request $request, SalesProfile $profile): JsonResponse
    {
        $this->scope->assertProfile(
            $request->user(),
            $profile,
            'sales.profiles.manage-all',
            'sales.profiles.manage-team',
        );
        $data = $request->validate([
            'to_status' => ['required', Rule::in(['active', 'suspended', 'ended'])],
            'expected_status' => ['required', Rule::in(['active', 'suspended', 'ended'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $profile = DB::transaction(function () use ($profile, $data, $request) {
            $profile = SalesProfile::query()->lockForUpdate()->findOrFail($profile->id);
            abort_unless($profile->status === $data['expected_status'], 409, 'The Sales Profile status changed; reload before retrying.');
            $allowed = ['active' => ['suspended', 'ended'], 'suspended' => ['active', 'ended'], 'ended' => []];
            abort_unless(in_array($data['to_status'], $allowed[$profile->status] ?? [], true), 422, 'The requested Sales Profile transition is not allowed.');

            if ($data['to_status'] !== 'active') {
                abort_if(DB::table('sales_booking_attributions')
                    ->where('collection_sales_profile_id', $profile->id)
                    ->where('status', 'active')
                    ->exists(), 422, 'Transfer the active collection portfolio before suspending or ending this Sales Profile.');
                abort_if(DB::table('sales_reporting_assignments')
                    ->where(fn ($query) => $query
                        ->where('manager_sales_profile_id', $profile->id)
                        ->orWhere('member_sales_profile_id', $profile->id))
                    ->where('effective_from', '<=', now())
                    ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                    ->exists(), 422, 'End or replace active Sales reporting assignments before suspending or ending this Profile.');
            }
            if ($data['to_status'] === 'active') {
                $staff = Staff::query()->lockForUpdate()->findOrFail($profile->staff_id);
                $salesStaffCategories = $this->salesStaffCategories((string) $profile->company_id);
                abort_if($profile->effective_until && $profile->effective_until->lte(now()), 422, 'An ended effective interval cannot be reactivated.');
                abort_unless(
                    $profile->reporting_currency
                    && $profile->staff_category_snapshot
                    && ($profile->acquisition_eligible || $profile->collection_eligible || $profile->commission_eligible),
                    422,
                    'A Sales Profile with missing Staff-category, eligibility, or reporting-currency evidence cannot be activated.',
                );
                abort_unless(
                    $salesStaffCategories !== []
                    && $this->isSalesStaffCategory($staff->staff_type, $salesStaffCategories)
                    && mb_strtolower(trim((string) $profile->staff_category_snapshot))
                        === mb_strtolower(trim((string) $staff->staff_type)),
                    422,
                    'Reconcile the linked Staff category through Profile configuration before reactivation.',
                );
            }

            $from = $profile->status;
            $before = $this->configurationSnapshot($profile);
            $profile->update([
                'status' => $data['to_status'],
                'effective_until' => $data['to_status'] === 'ended' ? now() : $profile->effective_until,
                'version' => $profile->version + 1,
                'updated_user_id' => $request->user()->id,
            ]);
            $after = $this->configurationSnapshot($profile);
            DB::table('sales_profile_events')->insert([
                'id' => (string) Str::uuid(),
                'sales_profile_id' => $profile->id,
                'profile_version' => $profile->version,
                'event_type' => 'status_changed',
                'from_status' => $from,
                'to_status' => $data['to_status'],
                'reason' => $data['reason'],
                'before_configuration' => CanonicalJson::encode($before),
                'after_configuration' => CanonicalJson::encode($after),
                'idempotency_key' => null,
                'actor_user_id' => $request->user()->id,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->auditConfigurationChange($profile, 'sales.profile.status_changed', $before, $after, $data['reason'], $request);

            return $profile;
        });

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($profile->load('staff.user')),
        ]);
    }

    public function events(Request $request, SalesProfile $profile): JsonResponse
    {
        $this->scope->assertProfile(
            $request->user(),
            $profile,
            'sales.profiles.view-all',
            'sales.profiles.view-team',
        );

        return response()->json(['status' => 'success', 'data' => DB::table('sales_profile_events')
            ->where('sales_profile_id', $profile->id)
            ->orderByDesc('occurred_at')
            ->get([
                'id', 'profile_version', 'event_type', 'from_status', 'to_status', 'reason',
                'before_configuration', 'after_configuration', 'actor_user_id', 'occurred_at',
            ])]);
    }

    private function payload(SalesProfile $profile, ?bool $canManage = null): array
    {
        $payload = [
            'id' => $profile->id,
            'company_id' => $profile->company_id,
            'staff_id' => $profile->staff_id,
            'staff_category_snapshot' => $profile->staff_category_snapshot,
            'sales_code' => $profile->sales_code,
            'status' => $profile->status,
            'version' => $profile->version,
            'acquisition_eligible' => $profile->acquisition_eligible,
            'collection_eligible' => $profile->collection_eligible,
            'commission_eligible' => $profile->commission_eligible,
            'reporting_currency' => $profile->reporting_currency,
            'effective_from' => $profile->effective_from,
            'effective_until' => $profile->effective_until,
            'staff' => $profile->staff ? [
                'id' => $profile->staff->id,
                'code' => $profile->staff->code,
                'name' => trim((string) ($profile->staff->user?->first_name.' '.$profile->staff->user?->last_name)),
            ] : null,
        ];

        if ($canManage !== null) {
            $payload['can_manage'] = $canManage;
        }

        return $payload;
    }

    private function configurationSnapshot(SalesProfile $profile): array
    {
        return $profile->only([
            'company_id',
            'staff_id',
            'staff_category_snapshot',
            'sales_code',
            'status',
            'version',
            'acquisition_eligible',
            'collection_eligible',
            'commission_eligible',
            'reporting_currency',
            'effective_from',
            'effective_until',
        ]);
    }

    private function salesStaffCategories(string $companyId): array
    {
        return $this->policySettings->approvedStaffCategories($companyId);
    }

    private function isSalesStaffCategory(?string $category, array $allowed): bool
    {
        $normalized = mb_strtolower(trim((string) $category));
        return $normalized !== '' && collect($allowed)
            ->contains(fn (string $candidate) => mb_strtolower(trim($candidate)) === $normalized);
    }

    private function auditConfigurationChange(
        SalesProfile $profile,
        string $eventType,
        array $before,
        array $after,
        string $reason,
        Request $request,
    ): void {
        DB::table('domain_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'domain' => 'sales',
            'company_id' => $profile->company_id,
            'subject_type' => 'sales_profile',
            'subject_id' => $profile->id,
            'event_type' => $eventType,
            'actor_user_id' => $request->user()->id,
            'actor_type' => 'user',
            'correlation_id' => $request->header('X-Correlation-ID'),
            'source_ip' => $request->ip(),
            'before_checksum' => hash('sha256', CanonicalJson::encode($before)),
            'after_checksum' => hash('sha256', CanonicalJson::encode($after)),
            'reason' => $reason,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fail(string $code, string $message, int $status): never
    {
        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ], $status));
    }
}
