<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteSetting;
use App\Services\TenantDecisionMutationService;
use App\Services\TenantDecisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class TenantDecisionController extends Controller
{
    public function __construct(private readonly TenantDecisionMutationService $mutations, private readonly TenantDecisionService $decisions) {}

    private function defs(): array
    {
        return config('tenant_decisions', []);
    }
    private function scope(Request $r, string $id): void
    {
        $ids = DB::table('staff')->where('user_id', $r->user()->id)->whereNull('deleted_at')->whereNull('employment_ended_at')->pluck('company_id')->all();
        abort_unless($r->user()->can('tenant-decisions.manage-all') || in_array($id, $ids, true), 403, 'Decision is outside your legal entity.');
    }
    public function companyOptions(Request $r)
    {
        $d = $r->validate(['search' => 'nullable|string|max:120', 'selected_id' => 'nullable|uuid', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        $ids = $r->user()->can('tenant-decisions.manage-all') ? null : DB::table('staff')->where('user_id', $r->user()->id)->whereNull('deleted_at')->whereNull('employment_ended_at')->pluck('company_id');
        $q = DB::table('companies')->whereNull('deleted_at')->when($d['selected_id'] ?? null, fn ($query, $id) => $query->where('id', $id));
        if ($ids !== null)
            $q->whereIn('id', $ids);
        if (empty($d['selected_id']) && ! empty($d['search'])) {
            $term = '%'.addcslashes($d['search'], '%_\\').'%';
            $q->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $q->orderBy('name')->paginate($d['per_page'] ?? 25, ['id', 'name', 'city']);
        $rows->getCollection()->transform(fn ($company) => ['value' => (string) $company->id, 'label' => (string) $company->name, 'metadata' => ['city' => $company->city], 'status' => 'active']);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }
    public function index(Request $r)
    {
        $d = $r->validate(['company_id' => 'required|uuid|exists:companies,id']);
        $this->scope($r, $d['company_id']);
        $rows = WebsiteSetting::where('company_id', $d['company_id'])->whereIn('type', collect($this->defs())->map(fn($x) => 'decision.' . $x['key']))->get()->keyBy('type');
        $defs = $this->defs();
        $configured = 0;
        $draft = 0;
        $missing = 0;
        $invalid = 0;
        $expiring = 0;
        $blockers = [];
        $activeByKey = [];
        foreach ($defs as $definition) {
            $active = $this->decisions->activeVersion($definition['key'], $d['company_id']);
            if (! $active) continue;
            try {
                $activeByKey[$definition['key']] = [
                    'version' => $active,
                    'value' => $this->decisions->validate(
                        $definition['key'],
                        json_decode((string) $active->value, true, 512, JSON_THROW_ON_ERROR)
                    ),
                ];
            } catch (\JsonException|ValidationException) {
                $activeByKey[$definition['key']] = ['version' => $active, 'value' => null];
            }
        }
        $labels = collect($defs)->pluck('label', 'key');
        foreach ($defs as &$x) {
            $row = $rows->get('decision.' . $x['key']);
            $payload = $row ? json_decode($row->value, true) : null;
            $latest = DB::table('tenant_decision_versions')->where('company_id', $d['company_id'])->where('decision_key', $x['key'])->orderByDesc('version')->first();
            $latestValue = $latest ? json_decode((string) $latest->value, true) : null;
            $x['value'] = is_array($latestValue) ? $latestValue : (is_array($payload['value'] ?? null) ? $payload['value'] : ($x['initial_template'] ?? []));
            $x['status'] = $latest->status ?? ($payload['status'] ?? 'not_configured');
            $x['version'] = $latest->version ?? ($payload['version'] ?? null);
            $x['effective_from'] = $latest->effective_from ?? null;
            $x['effective_until'] = $latest->effective_until ?? null;
            $x['updated_by'] = $latest->prepared_by ?? ($payload['updated_by'] ?? null);
            $x['approved_by'] = $latest->approved_by ?? ($payload['approved_by'] ?? null);
            $x['approved_at'] = $latest->approved_at ?? ($payload['approved_at'] ?? null);
            $active = $activeByKey[$x['key']] ?? null;
            $x['configured'] = is_array($active['value'] ?? null);
            $x['invalid'] = $active !== null && ! $x['configured'];
            $x['active_version'] = $active['version']->version ?? null;
            $x['active_value'] = $active['value'] ?? null;
            $x['pending_approval'] = ($x['status'] === 'draft');
            $x['blocked_by'] = collect($x['depends_on'] ?? [])->filter(
                fn ($key) => ! is_array($activeByKey[$key]['value'] ?? null)
            )->values()->all();
            $x['blocked_by_labels'] = collect($x['blocked_by'])->map(fn ($key) => $labels[$key] ?? $key)->values()->all();
            $x['expiring'] = $x['configured'] && ! empty($active['version']->effective_until)
                && $active['version']->effective_until <= now()->addDays(30)->toDateString();
            $x['proposed_changes'] = $x['pending_approval'] ? collect($x['field_schema'])->map(function ($field) use ($x) {
                $key = $field['key'];
                $before = $x['active_value'][$key] ?? null;
                $after = $x['value'][$key] ?? null;
                return $before === $after ? null : ['label' => $field['label'], 'before' => $before, 'after' => $after];
            })->filter()->values()->all() : [];
            $x['next_action'] = $x['invalid'] ? 'Replace the invalid approved version with a schema-valid draft.'
                : ($x['blocked_by_labels'] ? 'Configure first: '.implode(', ', $x['blocked_by_labels']).'.'
                : ($x['pending_approval'] ? 'Await independent approval.'
                : ($x['expiring'] ? 'Prepare and approve a replacement before expiry.'
                : ($x['configured'] ? 'No configuration action required.' : 'Prepare this required decision.'))));
            if ($x['configured'])
                $configured++;
            if ($x['pending_approval'])
                $draft++;
            if ($x['invalid'])
                $invalid++;
            if ($x['expiring'])
                $expiring++;
            if (! $x['configured']) {
                $blockers[] = ['key' => $x['key'], 'label' => $x['label'], 'module' => $x['module'], 'risk' => $x['risk'], 'status' => $x['status'], 'invalid' => $x['invalid'], 'blocked_by' => $x['blocked_by_labels'], 'next_action' => $x['next_action']];
                if (! $x['pending_approval']) $missing++;
            }
        }
        unset($x);
        $actorIds = collect($defs)->flatMap(fn ($definition) => [$definition['updated_by'], $definition['approved_by']])->filter()->unique()->values();
        $actorLabels = DB::table('users')->whereIn('id', $actorIds)->get(['id', 'first_name', 'last_name'])->mapWithKeys(
            fn ($user) => [$user->id => trim($user->first_name.' '.$user->last_name)]
        );
        foreach ($defs as &$x) {
            $x['last_editor_label'] = $x['updated_by'] ? ($actorLabels[$x['updated_by']] ?? 'Unavailable user') : 'Not prepared';
            $x['last_approver_label'] = $x['approved_by'] ? ($actorLabels[$x['approved_by']] ?? 'Unavailable user') : 'Not approved';
            unset($x['updated_by'], $x['approved_by']);
        }
        unset($x);
        $total = count($defs);
        return response()->json(['status' => 'success', 'data' => ['definitions' => $defs, 'readiness' => [
            'configured' => $configured, 'draft' => $draft, 'missing' => $missing, 'invalid' => $invalid, 'expiring' => $expiring,
            'total' => $total, 'activation_ready' => $total > 0 && $configured === $total, 'blockers' => $blockers,
        ]]]);
    }
    public function store(Request $r)
    {
        $d = $r->validate(['company_id' => 'required|uuid|exists:companies,id', 'key' => 'required|string', 'value' => 'required|array', 'effective_from' => 'required|date_format:Y-m-d', 'effective_until' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from', 'reason' => 'required|string|min:3', 'idempotency_key' => 'required|uuid']);
        $this->scope($r, $d['company_id']);
        abort_unless(collect($this->defs())->pluck('key')->contains($d['key']), 422, 'Unknown decision.');
        $d['value'] = $this->decisions->validate($d['key'], $d['value']);
        $admin = $r->user()->can('tenant-decisions.manage-all');
        $row = $this->mutations->draft($d, (string) $r->user()->id, $admin);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function approve(Request $r, string $key)
    {
        $d = $r->validate(['company_id' => 'required|uuid|exists:companies,id', 'idempotency_key' => 'required|uuid']);
        $this->scope($r, $d['company_id']);
        $row = $this->mutations->approve($d['company_id'], $key, (string) $r->user()->id, $d['idempotency_key']);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
}
