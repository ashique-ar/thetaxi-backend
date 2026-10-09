<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesEvidenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['booking', 'booking_payment_receipt', 'commission_statement_line', 'commission_statement', 'commission_payout'])],
            'subject_id' => ['required', 'uuid'],
        ]);
        $companyId = $this->authorizeSubject($request, $data['subject_type'], $data['subject_id'], true);
        $rows = DB::table('domain_evidence_files')->where('domain', 'sales')->where('company_id', $companyId)
            ->where('subject_type', $data['subject_type'])->where('subject_id', $data['subject_id'])->whereNull('deleted_at')
            ->select(['id', 'evidence_type', 'classification', 'file_name', 'mime_type', 'file_size', 'file_checksum',
                'version', 'supersedes_id', 'retention_until', 'created_at'])
            ->orderByDesc('created_at')->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function options(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['booking', 'booking_payment_receipt', 'commission_statement_line', 'commission_statement', 'commission_payout'])],
            'subject_id' => ['required', 'uuid'],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $companyId = $this->authorizeSubject($request, $data['subject_type'], $data['subject_id'], true);
        $query = DB::table('domain_evidence_files')->where('domain', 'sales')->where('company_id', $companyId)
            ->where('subject_type', $data['subject_type'])->where('subject_id', $data['subject_id'])->whereNull('deleted_at')
            ->select(['id', 'evidence_type', 'classification', 'file_name', 'version', 'created_at']);
        if (! empty($data['selected_id'])) {
            $query->where('id', $data['selected_id']);
        } elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('file_name', 'like', $term)->orWhere('evidence_type', 'like', $term));
        }
        $map = static fn ($row) => [
            'value' => (string) $row->id,
            'label' => $row->file_name,
            'metadata' => ['type' => $row->evidence_type, 'version' => 'v'.$row->version, 'classification' => $row->classification],
        ];
        if (! empty($data['selected_id'])) {
            return response()->json(['status' => 'success', 'data' => $query->limit(1)->get()->map($map)->values()]);
        }
        $rows = $query->orderByDesc('created_at')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));
        $rows->getCollection()->transform($map);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt'],
            'subject_type' => ['required', Rule::in(['booking', 'booking_payment_receipt', 'commission_statement_line', 'commission_statement', 'commission_payout'])],
            'subject_id' => ['required', 'uuid'],
            'evidence_type' => ['required', 'string', 'max:80'],
            'classification' => ['required', Rule::in(['internal', 'confidential', 'restricted'])],
            'retention_until' => ['nullable', 'date', 'after:today'],
            'supersedes_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
        ]);
        $companyId = $this->authorizeSubject($request, $data['subject_type'], $data['subject_id'], true);
        $superseded = null;
        if (! empty($data['supersedes_id'])) {
            $superseded = DB::table('domain_evidence_files')->where('id', $data['supersedes_id'])->whereNull('deleted_at')->first();
            abort_unless($superseded && $superseded->domain === 'sales' && $superseded->company_id === $companyId
                && $superseded->subject_type === $data['subject_type'] && $superseded->subject_id === $data['subject_id'],
                422, 'Superseded evidence must belong to the same Sales subject and legal entity.');
        }

        $file = $data['file'];
        $id = (string) Str::uuid();
        $extension = strtolower((string) $file->guessExtension());
        abort_unless(preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1, 422, 'The uploaded evidence type could not be safely identified.');
        $storedName = $id.'.'.$extension;
        $path = $companyId.'/'.now()->format('Y/m').'/'.$storedName;
        $temporaryPath = $file->getRealPath();
        abort_unless(is_string($temporaryPath) && $temporaryPath !== '', 422, 'The uploaded evidence could not be read.');
        $checksum = hash_file('sha256', $temporaryPath);
        abort_unless(is_string($checksum) && strlen($checksum) === 64, 422, 'The uploaded evidence checksum could not be created.');
        $disk = Storage::disk('sales_private');

        try {
            abort_unless($disk->putFileAs(dirname($path), $file, basename($path)) !== false,
                500, 'The private evidence object could not be stored.');
            $storedChecksum = hash_file('sha256', $disk->path($path));
            abort_unless(is_string($storedChecksum) && hash_equals($checksum, $storedChecksum),
                500, 'The stored private evidence object does not match its upload checksum.');

            DB::transaction(function () use ($request, $data, $companyId, $file, $id, $path, $checksum, $superseded): void {
                DB::table('domain_evidence_files')->insert([
                    'id' => $id, 'domain' => 'sales', 'company_id' => $companyId,
                    'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id'],
                    'evidence_type' => $data['evidence_type'], 'classification' => $data['classification'],
                    'disk' => 'sales_private', 'path' => $path,
                    'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => $file->getMimeType() ?: null, 'file_size' => (int) $file->getSize(),
                    'file_checksum' => $checksum, 'version' => $superseded ? ((int) $superseded->version + 1) : 1,
                    'supersedes_id' => $superseded?->id, 'retention_until' => $data['retention_until'] ?? null,
                    'uploaded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit($request, $companyId, $id, 'sales.evidence.uploaded', $checksum);
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['status' => 'success', 'data' => $this->publicRow($id)], 201);
    }

    public function download(Request $request, string $evidence): StreamedResponse
    {
        $row = DB::table('domain_evidence_files')->where('id', $evidence)->where('domain', 'sales')->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        $companyId = $this->authorizeSubject($request, $row->subject_type, $row->subject_id, false, $row->uploaded_by);
        abort_unless(hash_equals((string) $companyId, (string) $row->company_id), 404);
        $pathPattern = '#^'.preg_quote((string) $companyId, '#').'/\d{4}/(?:0[1-9]|1[0-2])/'.preg_quote((string) $row->id, '#').'(?:\.[a-z0-9]{1,10})?$#i';
        abort_unless($row->disk === 'sales_private' && preg_match($pathPattern, (string) $row->path) === 1, 404);
        $disk = Storage::disk('sales_private');
        abort_unless($disk->exists($row->path), 404, 'The private evidence object is unavailable.');
        $storedChecksum = hash_file('sha256', $disk->path($row->path));
        abort_unless(is_string($storedChecksum) && hash_equals((string) $row->file_checksum, $storedChecksum),
            409, 'The private evidence object failed its integrity check.');
        $this->audit($request, $row->company_id, $row->id, 'sales.evidence.downloaded', $row->file_checksum);

        return $disk->download($row->path, $row->file_name, [
            'Content-Type' => $row->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function authorizeSubject(Request $request, string $type, string $id, bool $upload, ?string $uploadedBy = null): string
    {
        if ($type === 'booking') {
            $subject = DB::table('sales_booking_attributions')->where('booking_id', $id)->first();
            abort_unless($subject, 404);
            if ($upload) {
                if (! $request->user()->can('sales.payment-ledger.reconcile')) {
                    abort_unless($request->user()->can('sales.collections.submit'), 403);
                    $profileIds = DB::table('sales_profiles as profile')->join('staff', 'staff.id', '=', 'profile.staff_id')
                        ->where('staff.user_id', $request->user()->id)->whereNull('staff.deleted_at')
                        ->where(fn ($q) => $q->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
                        ->whereColumn('profile.company_id', 'staff.company_id')
                        ->where('profile.collection_eligible', true)->whereNotNull('profile.reporting_currency')
                        ->whereNotNull('profile.staff_category_snapshot')->whereNull('profile.deleted_at')->where('profile.status', 'active')
                        ->where('profile.effective_from', '<=', now())
                        ->where(fn ($q) => $q->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                        ->pluck('profile.id')->all();
                    abort_unless(in_array($subject->collection_sales_profile_id, $profileIds, true), 403, 'The booking is outside your current collection portfolio.');
                }
            } else {
                abort_unless($uploadedBy === $request->user()->id
                    || $request->user()->canAny(['sales.collections.verify', 'sales.payment-ledger.reconcile']), 403);
            }
            $companyId = $subject->company_id;
        } elseif ($type === 'booking_payment_receipt') {
            $subject = DB::table('booking_payment_receipts')->where('id', $id)->first();
            abort_unless($subject, 404);
            abort_unless($request->user()->canAny(['sales.payment-finality.transition', 'sales.payment-ledger.reconcile']), 403);
            $companyId = $subject->company_id;
        } elseif ($type === 'commission_statement_line') {
            $subject = DB::table('sales_commission_statement_lines as line')
                ->join('sales_commission_statements as statement', 'statement.id', '=', 'line.statement_id')
                ->where('line.id', $id)->select('statement.company_id', 'statement.staff_id')->first();
            abort_unless($subject, 404);
            $staffId = DB::table('staff')->where('user_id', $request->user()->id)->whereNull('deleted_at')->value('id');
            abort_unless((! $upload && $request->user()->can('sales.commission-disputes.resolve'))
                || ($request->user()->can('sales.commission-disputes.raise') && $subject->staff_id === $staffId), 403);
            $companyId = $subject->company_id;
        } elseif ($type === 'commission_statement') {
            $subject = DB::table('sales_commission_statements')->where('id', $id)->first();
            abort_unless($subject, 404);
            abort_unless($upload ? $request->user()->can('sales.commission-payouts.pay')
                : $request->user()->canAny(['sales.commission-payouts.pay', 'sales.commission-payouts.reverse', 'sales.commission-accounting.acknowledge']), 403);
            $companyId = $subject->company_id;
        } else {
            $subject = DB::table('sales_commission_payouts')->where('id', $id)->first();
            abort_unless($subject, 404);
            abort_unless($upload ? $request->user()->can('sales.commission-payouts.reverse')
                : $request->user()->canAny(['sales.commission-payouts.reverse', 'sales.commission-accounting.acknowledge']), 403);
            $companyId = $subject->company_id;
        }
        $allScopePermission = match ($type) {
            'booking' => 'sales.collections.view-all',
            'booking_payment_receipt' => 'sales.payment-finality.manage-all',
            default => 'sales.commission-statements.view-all',
        };
        $canManageAllCompanies = $request->user()->can($allScopePermission)
            || ($type === 'booking_payment_receipt'
                && $request->user()->can('sales.payment-ledger.reconcile')
                && $request->user()->can('sales.collections.view-all'));
        abort_unless($canManageAllCompanies || in_array($companyId, $this->actorCompanyIds($request), true),
            403, 'The evidence subject is outside your legal entity.');

        return $companyId;
    }

    private function actorCompanyIds(Request $request): array
    {
        return DB::table('staff')->where('user_id', $request->user()->id)->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->pluck('company_id')->filter()->unique()->values()->all();
    }

    private function publicRow(string $id): object
    {
        return DB::table('domain_evidence_files')->where('id', $id)->select([
            'id', 'subject_type', 'subject_id', 'evidence_type', 'classification', 'file_name', 'mime_type',
            'file_size', 'file_checksum', 'version', 'supersedes_id', 'retention_until', 'created_at',
        ])->first();
    }

    private function audit(Request $request, ?string $companyId, string $evidenceId, string $eventType, string $checksum): void
    {
        DB::table('domain_audit_events')->insert([
            'id' => (string) Str::uuid(), 'domain' => 'sales', 'company_id' => $companyId,
            'subject_type' => 'domain_evidence_file', 'subject_id' => $evidenceId, 'event_type' => $eventType,
            'actor_user_id' => $request->user()->id, 'actor_type' => 'user',
            'correlation_id' => $request->header('X-Correlation-ID'), 'source_ip' => $request->ip(),
            'before_checksum' => null, 'after_checksum' => $checksum, 'reason' => null,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
