<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesReportingAssignment;
use App\Services\Sales\SalesAccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesReportingAssignmentController extends Controller
{
    public function __construct(private readonly SalesAccessScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid', 'exists:companies,id'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $profileIds = $this->scope->profileIds(
            $request->user(),
            'sales.profiles.view-all',
            'sales.profiles.view-team',
            $data['company_id'],
        );
        $manageableIds = $this->scope->authorizedProfileIds(
            $request->user(),
            'sales.profiles.manage',
            'sales.profiles.manage-all',
            'sales.profiles.manage-team',
            $data['company_id'],
        );
        $rows = SalesReportingAssignment::query()
            ->with(['manager.staff.user', 'member.staff.user'])
            ->where('company_id', $data['company_id'])
            ->when($profileIds !== null, fn ($query) => $query
                ->whereIn('manager_sales_profile_id', $profileIds)
                ->whereIn('member_sales_profile_id', $profileIds))
            ->orderByDesc('effective_from')
            ->paginate($request->integer('per_page', 50));
        $rows->setCollection($rows->getCollection()->map(fn ($row) => [
            'id' => $row->id, 'company_id' => $row->company_id,
            'manager_sales_profile_id' => $row->manager_sales_profile_id,
            'member_sales_profile_id' => $row->member_sales_profile_id,
            'manager' => $this->profileReference($row->manager),
            'member' => $this->profileReference($row->member),
            'team_code' => $row->team_code, 'effective_from' => $row->effective_from,
            'effective_until' => $row->effective_until,
            'can_manage' => $manageableIds === null
                || (in_array($row->manager_sales_profile_id, $manageableIds, true)
                    && in_array($row->member_sales_profile_id, $manageableIds, true)),
        ]));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'manager_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id', 'different:member_sales_profile_id'],
            'member_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id'],
            'team_code' => ['nullable', 'string', 'max:80'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.profiles.manage-all');

        $assignment = DB::transaction(function () use ($data, $request) {
            DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->firstOrFail();
            $profiles = SalesProfile::query()
                ->whereIn('id', [$data['manager_sales_profile_id'], $data['member_sales_profile_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            abort_unless($profiles->count() === 2, 422, 'Manager and member Sales Profiles are required.');

            $manager = $profiles->get($data['manager_sales_profile_id']);
            $member = $profiles->get($data['member_sales_profile_id']);
            $this->scope->assertProfile($request->user(), $manager, 'sales.profiles.manage-all', 'sales.profiles.manage-team');
            $this->scope->assertProfile($request->user(), $member, 'sales.profiles.manage-all', 'sales.profiles.manage-team');
            abort_unless($profiles->every(fn (SalesProfile $profile) => $profile->company_id === $data['company_id']),
                422, 'Manager and member must belong to the selected legal entity.');
            abort_unless($profiles->every(fn (SalesProfile $profile) => $this->supportsAssignmentInterval($profile, $data)),
                422, 'Reporting assignments must remain inside configured active Sales Profile intervals.');

            $overlap = SalesReportingAssignment::query()
                ->where('member_sales_profile_id', $data['member_sales_profile_id'])
                ->where('effective_from', '<', $data['effective_until'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $data['effective_from']))
                ->lockForUpdate()
                ->first(['id']);
            abort_if($overlap, 422, 'The member already has an overlapping reporting assignment.');
            $edges = SalesReportingAssignment::query()
                ->where('company_id', $data['company_id'])
                ->where('effective_from', '<', $data['effective_until'] ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $data['effective_from']))
                ->lockForUpdate()->get(['manager_sales_profile_id', 'member_sales_profile_id']);
            $graph = [];
            foreach ($edges as $edge) $graph[$edge->manager_sales_profile_id][] = $edge->member_sales_profile_id;
            $pending = [$data['member_sales_profile_id']]; $visited = [];
            while ($pending) {
                $node = array_pop($pending);
                if ($node === $data['manager_sales_profile_id']) abort(422, 'The reporting assignment would create a hierarchy cycle.');
                if (isset($visited[$node])) continue;
                $visited[$node] = true;
                array_push($pending, ...($graph[$node] ?? []));
            }

            $payload = collect($data)->except('reason')->all();
            $assignment = SalesReportingAssignment::create($payload + ['created_user_id' => $request->user()->id]);
            DB::table('sales_reporting_assignment_events')->insert([
                'id' => (string) Str::uuid(), 'sales_reporting_assignment_id' => $assignment->id,
                'event_type' => 'assigned', 'before_snapshot' => null,
                'after_snapshot' => json_encode($assignment->only(['company_id', 'manager_sales_profile_id', 'member_sales_profile_id', 'team_code', 'effective_from', 'effective_until']), JSON_THROW_ON_ERROR),
                'reason' => $data['reason'], 'actor_user_id' => $request->user()->id,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $assignment;
        });

        return response()->json(['status' => 'success', 'data' => $assignment], 201);
    }

    public function end(Request $request, SalesReportingAssignment $assignment): JsonResponse
    {
        $this->scope->assertCompany($request->user(), $assignment->company_id, 'sales.profiles.manage-all');
        $data = $request->validate([
            'effective_until' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $assignment = DB::transaction(function () use ($assignment, $data, $request) {
            DB::table('companies')->where('id', $assignment->company_id)->lockForUpdate()->firstOrFail();
            $assignment = SalesReportingAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $profiles = SalesProfile::query()
                ->withTrashed()
                ->whereIn('id', [$assignment->manager_sales_profile_id, $assignment->member_sales_profile_id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless($profiles->count() === 2, 422, 'The reporting assignment Profiles could not be reconciled.');
            foreach ($profiles as $profile) {
                $this->scope->assertProfile($request->user(), $profile, 'sales.profiles.manage-all', 'sales.profiles.manage-team');
            }

            abort_unless($assignment->effective_from->lt($data['effective_until']), 422, 'The reporting end must be after its start.');
            abort_if($assignment->effective_until && $assignment->effective_until->lte($data['effective_until']), 422, 'The assignment already ends on or before the requested date.');
            abort_unless($profiles->every(fn (SalesProfile $profile) => ! $profile->effective_until
                || $profile->effective_until->gte($data['effective_until'])),
                422, 'The reporting end must remain inside both Sales Profile intervals.');
            $before = $assignment->only(['company_id', 'manager_sales_profile_id', 'member_sales_profile_id', 'team_code', 'effective_from', 'effective_until']);
            $assignment->update(['effective_until' => $data['effective_until'], 'updated_user_id' => $request->user()->id]);
            DB::table('sales_reporting_assignment_events')->insert([
                'id' => (string) Str::uuid(), 'sales_reporting_assignment_id' => $assignment->id,
                'event_type' => 'ended', 'before_snapshot' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode($assignment->only(array_keys($before)), JSON_THROW_ON_ERROR),
                'reason' => $data['reason'], 'actor_user_id' => $request->user()->id,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $assignment;
        });
        return response()->json(['status' => 'success', 'data' => $assignment]);
    }

    private function supportsAssignmentInterval(SalesProfile $profile, array $data): bool
    {
        return $profile->status === 'active'
            && preg_match('/^[A-Z]{3}$/', (string) $profile->reporting_currency) === 1
            && ($profile->acquisition_eligible || $profile->collection_eligible || $profile->commission_eligible)
            && $profile->effective_from->lte($data['effective_from'])
            && (! $profile->effective_until
                || ($data['effective_until'] && $profile->effective_until->gte($data['effective_until'])));
    }

    private function profileReference(?SalesProfile $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'id' => $profile->id,
            'sales_code' => $profile->sales_code,
            'status' => $profile->status,
            'staff' => $profile->staff ? [
                'code' => $profile->staff->code,
                'name' => trim((string) ($profile->staff->user?->first_name.' '.$profile->staff->user?->last_name)),
            ] : null,
        ];
    }
}
