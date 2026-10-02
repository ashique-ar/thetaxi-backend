<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CommissionStatementExportService
{
    public function generate(SalesCommissionStatement $statement, string $format, string $key, string $actorUserId): SalesCommissionStatementExport
    {
        return DB::transaction(function () use ($statement, $format, $key, $actorUserId) {
            $duplicate = SalesCommissionStatementExport::query()->where('idempotency_key', $key)->first();
            if ($duplicate) {
                abort_unless($duplicate->statement_id === $statement->id && $duplicate->format === $format, 422,
                    'This export key was already used for another statement or format.');
                return $duplicate;
            }
            $statement = SalesCommissionStatement::query()->with('lines')->findOrFail($statement->id);
            $content = $format === 'pdf'
                ? Pdf::loadView('sales.commission-statement', ['statement' => $statement])->output()
                : $this->csv($statement);
            $fileName = $statement->statement_number.'.'.$format;
            $path = 'commission-statements/'.$statement->company_id.'/'.$statement->staff_id.'/'.$fileName;
            Storage::disk('sales_private')->put($path, $content);
            return SalesCommissionStatementExport::create([
                'statement_id' => $statement->id, 'format' => $format, 'disk' => 'sales_private',
                'path' => $path, 'file_name' => $fileName, 'file_checksum' => hash('sha256', $content),
                'file_size' => strlen($content), 'generated_by' => $actorUserId, 'generated_at' => now(),
                'idempotency_key' => $key,
            ]);
        });
    }

    private function csv(SalesCommissionStatement $statement): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Statement', $statement->statement_number]);
        fputcsv($stream, ['Period', $statement->period_start->toDateString(), $statement->period_end->toDateString()]);
        fputcsv($stream, ['Timezone', $statement->timezone, 'Cutoff', $statement->cutoff_at->toIso8601String()]);
        fputcsv($stream, []);
        fputcsv($stream, ['Type', 'Description', 'Gross LKR', 'Deduction LKR', 'Net LKR', 'Status', 'Hold code', 'Checksum']);
        foreach ($statement->lines as $line) {
            fputcsv($stream, [$line->line_type, $line->description, $line->gross_lkr, $line->deduction_lkr,
                $line->net_lkr, $line->line_status, $line->hold_code, $line->snapshot_checksum]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Opening carry-forward', $statement->opening_carry_forward_lkr]);
        fputcsv($stream, ['Gross earnings', $statement->gross_earnings_lkr]);
        fputcsv($stream, ['Adjustment credits', $statement->adjustment_credits_lkr]);
        fputcsv($stream, ['Recoveries', $statement->recovery_deductions_lkr]);
        fputcsv($stream, ['Other deductions', $statement->other_deductions_lkr]);
        fputcsv($stream, ['Contested hold', $statement->contested_hold_lkr]);
        fputcsv($stream, ['Net payable', $statement->net_payable_lkr]);
        fputcsv($stream, ['Paid', $statement->paid_lkr]);
        fputcsv($stream, ['Closing carry-forward', $statement->closing_carry_forward_lkr]);
        rewind($stream); $content = stream_get_contents($stream); fclose($stream);
        return $content;
    }
}
