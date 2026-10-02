<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesProfileExport;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SalesProfileExportService
{
    public function __construct(private readonly SalesPolicySettingsService $policySettings) {}

    public function purgeExpired(): int
    {
        $purged = 0;
        SalesProfileExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $exports) use (&$purged): void {
                foreach ($exports as $export) {
                    $deleted = DB::transaction(function () use ($export): bool {
                        $locked = SalesProfileExport::query()->lockForUpdate()->find($export->id);
                        if (! $locked || ! $locked->expires_at?->lte(now())) {
                            return false;
                        }
                        if (Storage::disk($locked->disk)->exists($locked->path)
                            && ! Storage::disk($locked->disk)->delete($locked->path)) {
                            throw new RuntimeException('An expired Sales Profile export could not be removed from private storage.');
                        }

                        DB::table('domain_audit_events')->insert([
                            'id' => (string) Str::uuid(),
                            'domain' => 'sales',
                            'company_id' => $locked->company_id,
                            'subject_type' => 'sales_profile_export',
                            'subject_id' => $locked->id,
                            'event_type' => 'sales.profile_export.purged',
                            'actor_user_id' => null,
                            'actor_type' => 'system',
                            'correlation_id' => null,
                            'source_ip' => null,
                            'before_checksum' => $locked->file_checksum,
                            'after_checksum' => null,
                            'reason' => 'Approved Sales Profile export retention expired',
                            'occurred_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $locked->delete();

                        return true;
                    });
                    if ($deleted) {
                        $purged++;
                    }
                }
            });

        return $purged;
    }

    /**
     * Generate a private CSV roster export from an already row-scoped query
     * (the caller must apply the same self/legal-entity authorization it
     * uses for the equivalent list endpoint before calling this).
     */
    public function generate(
        Builder $scopedQuery,
        string $companyFilter,
        ?string $statusFilter,
        string $scopeType,
        string $key,
        string $actorUserId,
        ?string $correlationId = null,
        ?string $sourceIp = null,
    ): SalesProfileExport
    {
        $retentionDays = $this->policySettings->profileExportRetentionDays($companyFilter);
        if (! is_int($retentionDays) || $retentionDays < 1) {
            $this->fail(
                'CONFIGURATION_MISSING',
                'Sales Profile export retention must be approved and configured before generating roster files.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $requestChecksum = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'company_id' => $companyFilter,
            'scope_type' => $scopeType,
            'status_filter' => $statusFilter,
        ]));
        $scopedKey = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'idempotency_key' => $key,
        ]));
        $path = null;

        try {
            return DB::transaction(function () use (
                $scopedQuery,
                $companyFilter,
                $statusFilter,
                $scopeType,
                $actorUserId,
                $correlationId,
                $sourceIp,
                $retentionDays,
                $requestChecksum,
                $scopedKey,
                &$path,
            ) {
                DB::table('users')->where('id', $actorUserId)->lockForUpdate()->firstOrFail();

                $duplicate = SalesProfileExport::query()
                    ->where('idempotency_key', $scopedKey)
                    ->lockForUpdate()
                    ->first();
                if ($duplicate) {
                    if ($duplicate->generated_by !== $actorUserId
                        || ! hash_equals((string) $duplicate->request_checksum, $requestChecksum)) {
                        $this->fail(
                            'IDEMPOTENCY_PAYLOAD_MISMATCH',
                            'The export key was already used for a different actor, scope, or filter.',
                            Response::HTTP_UNPROCESSABLE_ENTITY,
                        );
                    }

                    return $duplicate;
                }

                $profiles = (clone $scopedQuery)
                    ->with('staff.user')
                    ->orderBy('sales_code')
                    ->orderBy('id')
                    ->get();
                $profileIds = $profiles->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
                $scopeChecksum = self::scopeChecksum($companyFilter, $scopeType, $profileIds);
                $content = $this->csv($profiles);
                $fileName = 'sales-profiles-'.now()->format('Ymd-His').'-'.Str::random(8).'.csv';
                $path = 'sales-profile-exports/'.$companyFilter.'/'.$fileName;

                if (! Storage::disk('sales_private')->put($path, $content)) {
                    throw new RuntimeException('The private Sales Profile export could not be written.');
                }

                $export = SalesProfileExport::create([
                    'company_id' => $companyFilter,
                    'status_filter' => $statusFilter,
                    'scope_type' => $scopeType,
                    'scope_profile_ids' => $profileIds,
                    'scope_checksum' => $scopeChecksum,
                    'request_checksum' => $requestChecksum,
                    'row_count' => $profiles->count(),
                    'disk' => 'sales_private',
                    'path' => $path,
                    'file_name' => $fileName,
                    'file_checksum' => hash('sha256', $content),
                    'file_size' => strlen($content),
                    'generated_by' => $actorUserId,
                    'generated_at' => now(),
                    'expires_at' => now()->addDays($retentionDays),
                    'idempotency_key' => $scopedKey,
                    'created_user_id' => $actorUserId,
                ]);

                DB::table('domain_audit_events')->insert([
                    'id' => (string) Str::uuid(),
                    'domain' => 'sales',
                    'company_id' => $companyFilter,
                    'subject_type' => 'sales_profile_export',
                    'subject_id' => $export->id,
                    'event_type' => 'sales.profile_export.generated',
                    'actor_user_id' => $actorUserId,
                    'actor_type' => 'user',
                    'correlation_id' => $correlationId,
                    'source_ip' => $sourceIp,
                    'before_checksum' => null,
                    'after_checksum' => $export->file_checksum,
                    'reason' => "{$scopeType} roster export with {$export->row_count} rows",
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $export;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('sales_private')->delete($path);
            }

            throw $exception;
        }
    }

    public static function scopeChecksum(string $companyId, string $scopeType, array $profileIds): string
    {
        sort($profileIds, SORT_STRING);

        return hash('sha256', CanonicalJson::encode([
            'company_id' => $companyId,
            'scope_type' => $scopeType,
            'profile_ids' => array_values($profileIds),
        ]));
    }

    private function csv(Collection $profiles): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('The Sales Profile export stream could not be opened.');
        }

        try {
            $this->writeRow($stream, [
                'Sales code', 'Company ID', 'Staff code', 'Staff name', 'Staff category snapshot', 'Status',
                'Acquisition eligible', 'Collection eligible', 'Commission eligible', 'Reporting currency',
                'Effective from', 'Effective until',
            ]);
            foreach ($profiles as $profile) {
                $this->writeRow($stream, [
                    $this->spreadsheetSafe($profile->sales_code),
                    $profile->company_id,
                    $this->spreadsheetSafe($profile->staff?->code),
                    $this->spreadsheetSafe(trim((string) ($profile->staff?->user?->first_name.' '.$profile->staff?->user?->last_name))),
                    $this->spreadsheetSafe($profile->staff_category_snapshot),
                    $profile->status,
                    $profile->acquisition_eligible ? 'yes' : 'no',
                    $profile->collection_eligible ? 'yes' : 'no',
                    $profile->commission_eligible ? 'yes' : 'no',
                    $profile->reporting_currency,
                    optional($profile->effective_from)->toDateString(),
                    optional($profile->effective_until)->toDateString(),
                ]);
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new RuntimeException('The Sales Profile export stream could not be read.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row) === false) {
            throw new RuntimeException('A Sales Profile export row could not be written.');
        }
    }

    private function spreadsheetSafe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $text) === 1 ? "'{$text}" : $text;
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
