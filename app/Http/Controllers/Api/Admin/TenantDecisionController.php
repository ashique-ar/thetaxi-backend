<?php
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class TenantDecisionController extends Controller
{
    private function defs(): array
    {
        return config('tenant_decisions', []);
    }
    private function scope(Request $r, string $id): void
    {
        $ids = DB::table('staff')->where('user_id', $r->user()->id)->whereNull('deleted_at')->pluck('company_id')->all();
        abort_unless($r->user()->can('tenant-decisions.manage-all') || in_array($id, $ids, true), 403, 'Decision is outside your legal entity.');
    }
    public function context(Request $r)
    {
        $ids = $r->user()->can('tenant-decisions.manage-all') ? null : DB::table('staff')->where('user_id', $r->user()->id)->pluck('company_id');
        $q = DB::table('companies')->whereNull('deleted_at');
        if ($ids !== null)
            $q->whereIn('id', $ids);
        return response()->json(['status' => 'success', 'data' => ['companies' => $q->orderBy('name')->get(['id', 'name'])]]);
    }
    public function index(Request $r)
    {
        $d = $r->validate(['company_id' => 'required|uuid|exists:companies,id']);
        $this->scope($r, $d['company_id']);
        $rows = WebsiteSetting::where('company_id', $d['company_id'])->whereIn('type', collect($this->defs())->map(fn($x) => 'decision.' . $x['key']))->get()->keyBy('type');
        $defs = $this->defs();
        $configured = 0;
        foreach ($defs as &$x) {
            $row = $rows->get('decision.' . $x['key']);
            $payload = $row ? json_decode($row->value, true) : null;
            $x['value'] = is_array($payload['value'] ?? null) ? $payload['value'] : ($x['initial_template'] ?? []);
            $x['status'] = $payload['status'] ?? 'not_configured';
            $x['configured'] = ($x['status'] === 'approved');
            if ($x['configured'])
                $configured++;
        }
        return response()->json(['status' => 'success', 'data' => ['definitions' => $defs, 'readiness' => ['configured' => $configured, 'total' => count($defs)]]]);
    }
    public function store(Request $r)
    {
        $d = $r->validate(['company_id' => 'required|uuid|exists:companies,id', 'key' => 'required|string', 'value' => 'required|array', 'reason' => 'required|string|min:3', 'idempotency_key' => 'required|uuid']);
        $this->scope($r, $d['company_id']);
        abort_unless(collect($this->defs())->pluck('key')->contains($d['key']), 422, 'Unknown decision.');
        $type = 'decision.' . $d['key'];
        $admin = $r->user()->can('tenant-decisions.manage-all');
        $payload = ['value' => $d['value'], 'status' => $admin ? 'approved' : 'draft', 'reason' => $d['reason'], 'idempotency_key' => $d['idempotency_key'], 'updated_by' => $r->user()->id];
        if ($admin) {
            $payload['approved_by'] = $r->user()->id;
            $payload['approved_at'] = now()->toISOString();
        }
        $row = WebsiteSetting::updateOrCreate(['type' => $type, 'company_id' => $d['company_id']], ['value' => json_encode($payload), 'updated_user_id' => $r->user()->id]);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
    public function approve(Request $r, string $key)
    {
        $d = $r->validate(['company_id' => 'required|uuid|exists:companies,id']);
        $this->scope($r, $d['company_id']);
        $row = WebsiteSetting::where('type', 'decision.' . $key)->where('company_id', $d['company_id'])->firstOrFail();
        $v = json_decode($row->value, true);
        abort_if(($v['updated_by'] ?? null) == $r->user()->id, 409, 'The decision author cannot approve the same change.');
        $v['status'] = 'approved';
        $v['approved_by'] = $r->user()->id;
        $v['approved_at'] = now()->toISOString();
        $row->update(['value' => json_encode($v), 'updated_user_id' => $r->user()->id]);
        return response()->json(['status' => 'success', 'data' => $row]);
    }
}
