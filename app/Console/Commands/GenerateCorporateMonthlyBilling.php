<?php

namespace App\Console\Commands;

use App\Models\Corporate\CorporateBillingTerm;
use App\Services\CorporateMonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateCorporateMonthlyBilling extends Command
{
    protected $signature = 'corporate:generate-monthly-billing {--date=}';
    protected $description = 'Generate due corporate monthly billing drafts from active billing terms';

    public function handle(CorporateMonthlyBillingService $billing): int
    {
        $date = Carbon::parse($this->option('date') ?: today())->startOfDay();
        $terms = CorporateBillingTerm::query()->whereDate('effective_from', '<=', $date)
            ->where(fn($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->where(fn($query) => $query->where('invoice_day', $date->day)
                ->when($date->isLastOfMonth(), fn($q) => $q->orWhere('invoice_day', '>', $date->day)))
            ->orderByDesc('effective_from')->get()->unique('corporate_id');

        foreach ($terms as $term) {
            [$start, $end] = self::period($date, (int) $term->cutoff_day);
            try {
                $settlement = $billing->generate($term->corporate_id, $start, $end, null);
                $this->info("{$term->corporate_id}: {$settlement->settlement_number}");
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->warn("{$term->corporate_id}: " . collect($exception->errors())->flatten()->first());
            } catch (\Throwable $exception) {
                report($exception);
                $this->error("{$term->corporate_id}: {$exception->getMessage()}");
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
