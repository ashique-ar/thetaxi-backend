<?php

namespace App\Console\Commands;

use App\Models\Corporate\CorporateReportSchedule;
use App\Services\CorporateManagementAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DeliverCorporateManagementReports extends Command
{
    protected $signature = 'corporate:deliver-management-reports {--date=}';
    protected $description = 'Deliver due permission-configured monthly corporate management reports';

    public function handle(CorporateManagementAnalyticsService $analytics): int
    {
        $date = $this->option('date') ? now()->parse($this->option('date')) : now();
        CorporateReportSchedule::where('is_active', true)
            ->where(fn ($query) => $query->where('delivery_day', '<=', $date->day)
                ->when($date->isLastOfMonth(), fn ($query) => $query->orWhere('delivery_day', '>', $date->day)))
            ->each(function ($schedule) use ($analytics, $date) {
            if ($schedule->last_delivered_at?->isSameMonth($date)) {
                return;
            }
            try {
                $filters = array_merge($schedule->filters ?? [], ['date_from' => $date->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'date_to' => $date->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()]);
                $payload = $analytics->report($schedule->corporate_id, $filters, true);
                if ($schedule->format === 'csv') {
                    $stream = fopen('php://temp', 'w+b');
                    fputcsv($stream, ['Month', 'Bookings', 'Trips', 'Estimated value', 'Finalized charges']);
                    foreach ($payload['monthly_trends'] as $row) {
                        fputcsv($stream, [$row['month'], $row['bookings'], $row['trips'], $row['estimated_value'], $row['finalized_charges']]);
                    }
                    if (isset($payload['financial_period']['summary'])) {
                        fputcsv($stream, []);
                        fputcsv($stream, ['Financial basis', 'Selected travel period']);
                        foreach ($payload['financial_period']['summary'] as $key => $value) {
                            fputcsv($stream, [str_replace('_', ' ', $key), $value]);
                        }
                    }
                    rewind($stream);
                    $bytes = stream_get_contents($stream);
                    fclose($stream);
                    $filename = 'corporate-management-report.csv';
                    $mime = 'text/csv';
                } else {
                    $bytes = Pdf::loadView('reports.corporate-management', ['payload' => $payload, 'rows' => []])->output();
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
}
