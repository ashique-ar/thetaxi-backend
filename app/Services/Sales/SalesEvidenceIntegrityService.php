<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SalesEvidenceIntegrityService
{
    public function assertAttachedFile(string $id, ?string $companyId, string $subjectType, array $subjectIds, bool $lock = false): object
    {
        $query = DB::table('domain_evidence_files')->where('id', $id)->where('domain', 'sales')
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->whereNull('deleted_at')
            ->where('subject_type', $subjectType)->whereIn('subject_id', $subjectIds);
        $evidence = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($evidence, 422, 'Evidence must be attached to the required Sales subject.');

        $ownerPath = $companyId !== null ? preg_quote($companyId, '#') : '[0-9a-f-]{36}';
        $pathPattern = '#^'.$ownerPath.'/\d{4}/(?:0[1-9]|1[0-2])/'
            .preg_quote((string) $evidence->id, '#').'(?:\.[a-z0-9]{1,10})?$#i';
        abort_unless($evidence->disk === 'sales_private' && preg_match($pathPattern, (string) $evidence->path) === 1
            && preg_match('/^[a-f0-9]{64}$/i', (string) $evidence->file_checksum) === 1, 409,
            'The attached Sales evidence location or checksum is invalid.');

        $disk = Storage::disk('sales_private');
        abort_unless($disk->exists($evidence->path), 409, 'The attached Sales evidence file is unavailable.');
        $content = $disk->get($evidence->path);
        abort_unless(is_string($content) && strlen($content) === (int) $evidence->file_size
            && hash_equals($evidence->file_checksum, hash('sha256', $content)), 409,
            'The attached Sales evidence file failed its integrity check.');

        return $evidence;
    }
}
