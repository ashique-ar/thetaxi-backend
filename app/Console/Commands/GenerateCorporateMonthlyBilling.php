<?php

namespace App\Console\Commands;

use App\Models\Corporate\CorporateBillingTerm;
use App\Services\CorporateMonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateCorporateMonthlyBilling extends Command
{
    protected $signature = 'corporate:generate-monthly-billing {--date=} {--months=1} {--issue}';
    protected $description = 'Generate due corporate billing, including late-finalized trips';

    public function handle(CorporateMonthlyBillingService $billing): int
    {
        $date = Carbon::parse($this->option('date') ?: today())->startOfDay();
        $months = max(1, min(60, (int) $this->option('months')));
        $corporateIds = CorporateBillingTerm::withInactive()->whereDate('effective_from', '<=', $date)
            ->distinct()->pluck('corporate_id');

        foreach ($corporateIds as $corporateId) {
            for ($offset = 0; $offset < $months; $offset++) {
                $invoiceMonth = $date->copy()->subMonthsNoOverflow($offset)->startOfMonth();
                $term = CorporateBillingTerm::withInactive()->where('corporate_id', $corporateId)
                    ->whereDate('effective_from', '<=', $invoiceMonth->copy()->endOfMonth())
                    ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $invoiceMonth))
                    ->orderByDesc('effective_from')->first();
                if (!$term) {
                    continue;
                }
                $invoiceDate = $invoiceMonth->copy()->day(min((int) $term->invoice_day, $invoiceMonth->daysInMonth));
                if ($invoiceDate->gt($date)) {
                    continue;
                }
                [$start, $end] = self::period($invoiceDate, (int) $term->cutoff_day);
                try {
                    $settlement = $billing->generate($corporateId, $start, $end, null);
                    if ($this->option('issue') && $settlement->status === 'draft') {
                        $settlement = $billing->issue($settlement);
                    }
                    $this->info("{$corporateId}: {$settlement->settlement_number} ({$settlement->status})");
                } catch (\Illuminate\Validation\ValidationException $exception) {
                    $this->line("{$corporateId}: " . collect($exception->errors())->flatten()->first());
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->error("{$corporateId}: {$exception->getMessage()}");
                }
            }
        }
        return self::SUCCESS;
    }

    public static function period(Carbon $invoiceDate, int $cutoffDay): array
    {
        $candidate = $invoiceDate->copy()->startOfMonth()->day(min($cutoffDay, $invoiceDate->daysInMonth));
        if ($candidate->gte($invoiceDate)) {
            $previous = $invoiceDate->copy()->subMonthNoOverflow();
            $candidate = $previous->copy()->startOfMonth()->day(min($cutoffDay, $previous->daysInMonth));
        }
        $previous = $candidate->copy()->subMonthNoOverflow();
        $start = $previous->copy()->startOfMonth()->day(min($cutoffDay, $previous->daysInMonth))->addDay();
        return [$start->toDateString(), $candidate->toDateString()];
    }
}
