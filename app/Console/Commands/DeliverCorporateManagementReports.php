<?php

namespace App\Console\Commands;

use App\Models\Corporate\CorporateReportSchedule;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Finance\FinancialAccountSettlement;
use App\Services\CorporateManagementAnalyticsService;
use App\Services\CorporatePortalPermission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DeliverCorporateManagementReports extends Command
{
    protected $signature = 'corporate:deliver-management-reports {--date=}';
    protected $description = 'Deliver due permission-configured monthly corporate management reports';

    public function handle(CorporateManagementAnalyticsService $analytics): int
    {
        if (!config('corporate_portal.scheduled_reports_enabled')) {
            return self::SUCCESS;
        }

        $date = $this->option('date') ? now()->parse($this->option('date')) : now();
        CorporateReportSchedule::where('is_active', true)
            ->where(fn ($query) => $query->where('delivery_day', '<=', $date->day)
                ->when($date->isLastOfMonth(), fn ($query) => $query->orWhere('delivery_day', '>', $date->day)))
            ->each(function ($schedule) use ($analytics, $date) {
            if ($schedule->last_delivered_at?->isSameMonth($date)) {
                return;
            }
            $creator = CorporateEmployee::where('corporate_id', $schedule->corporate_id)
                ->where('user_id', $schedule->created_by)
                ->first();
            if (! $creator || ! CorporatePortalPermission::employeeAllows($creator, 'schedule_reports')) {
                $schedule->update([
                    'is_active' => false,
                    'last_error' => 'Delivery stopped because the schedule owner no longer has corporate report scheduling access.',
                ]);
                return;
            }
            try {
                $periodStart = $date->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
                $periodEnd = $date->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();
                $filters = array_merge($schedule->filters ?? [], ['date_from' => $periodStart, 'date_to' => $periodEnd]);
                unset($filters['billing_period_id'], $filters['billing_period_ids']);
                $canViewFinance = CorporatePortalPermission::employeeAllows($creator, 'view_payments');
                if ($canViewFinance) {
                    $billingPeriodIds = FinancialAccountSettlement::query()
                        ->where('owner_type', 'corporate')
                        ->where('owner_id', $schedule->corporate_id)
                        ->whereNotIn('status', ['draft', 'void'])
                        ->whereDate('period_start', '<=', $periodEnd)
                        ->whereDate('period_end', '>=', $periodStart)
                        ->orderBy('period_start')
                        ->pluck('id')
                        ->all();
                    if ($billingPeriodIds) {
                        $filters['billing_period_ids'] = $billingPeriodIds;
                    }
                }
                $payload = $analytics->report($schedule->corporate_id, $filters, $canViewFinance);
                $rows = collect($payload['dimensions'])->flatMap(fn (array $values, string $dimension) => collect($values)->map(function (array $row) use ($dimension, $canViewFinance) {
                    $cells = [$dimension, $row['label'], $row['booking_count'], $row['trip_count']];
                    if ($canViewFinance) {
                        array_push($cells, $row['currency'], $row['estimated_value'], $row['finalized_charges']);
                    }
                    return $cells;
                }))->values()->all();
                if ($schedule->format === 'csv') {
                    $stream = fopen('php://temp', 'w+b');
                    fwrite($stream, "\xEF\xBB\xBF");
                    fputcsv($stream, $this->safeCsvRow(['Travel date range', $periodStart, $periodEnd]));
                    $headers = ['Month', 'Currency', 'Bookings', 'Trips'];
                    if ($canViewFinance) {
                        array_push($headers, 'Estimated value', 'Finalized charges');
                    }
                    fputcsv($stream, $this->safeCsvRow($headers));
                    foreach ($payload['monthly_trends'] as $row) {
                        $cells = [$row['month'], $row['currency'], $row['bookings'], $row['trips']];
                        if ($canViewFinance) {
                            array_push($cells, $row['estimated_value'], $row['finalized_charges']);
                        }
                        fputcsv($stream, $this->safeCsvRow($cells));
                    }
                    fputcsv($stream, []);
                    $headers = ['Dimension', 'Label', 'Bookings', 'Trips'];
                    if ($canViewFinance) {
                        array_push($headers, 'Currency', 'Estimated value', 'Finalized charges');
                    }
                    fputcsv($stream, $this->safeCsvRow($headers));
                    foreach ($rows as $row) {
                        fputcsv($stream, $this->safeCsvRow($row));
                    }
                    if (!empty($payload['financial_periods'])) {
                        fputcsv($stream, []);
                        fputcsv($stream, $this->safeCsvRow(['Billing period totals']));
                        foreach ($payload['financial_periods'] as $summary) {
                            foreach ($summary as $key => $value) {
                                fputcsv($stream, $this->safeCsvRow([str_replace('_', ' ', $key), $value]));
                            }
                            fputcsv($stream, []);
                        }
                    }
                    rewind($stream);
                    $bytes = stream_get_contents($stream);
                    fclose($stream);
                    $filename = 'corporate-management-report.csv';
                    $mime = 'text/csv';
                } else {
                    $bytes = Pdf::loadView('reports.corporate-management', compact('payload', 'rows'))->output();
                    $filename = 'corporate-management-report.pdf';
                    $mime = 'application/pdf';
                }
                Mail::raw('Your scheduled corporate management report is attached.', fn ($message) => $message->to($schedule->recipients)->subject($schedule->name)->attachData($bytes, $filename, ['mime' => $mime]));
                $schedule->update(['last_delivered_at' => now(), 'last_error' => null]);
            } catch (\Throwable $exception) {
                report($exception);
                $schedule->update(['last_failed_at' => now(), 'last_error' => str($exception->getMessage())->limit(1000)]);
            }
        });
        return self::SUCCESS;
    }

    private function safeCsvRow(array $row): array
    {
        return array_map(fn ($value) => is_string($value)
            && preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $value) === 1
                ? "'".$value
                : $value, $row);
    }
}
