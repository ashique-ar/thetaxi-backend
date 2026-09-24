<?php

namespace App\Console\Commands;

use App\Models\Corporate\CorporateReportSchedule;
use App\Models\Corporate\CorporateEmployee;
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
                $filters = array_merge($schedule->filters ?? [], ['date_from' => $date->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'date_to' => $date->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()]);
                $canViewFinance = CorporatePortalPermission::employeeAllows($creator, 'view_payments');
                $payload = $analytics->report($schedule->corporate_id, $filters, $canViewFinance);
                if ($schedule->format === 'csv') {
                    $stream = fopen('php://temp', 'w+b');
                    $headers = ['Month', 'Bookings', 'Trips'];
                    if ($canViewFinance) {
                        array_push($headers, 'Estimated value', 'Finalized charges');
                    }
                    fputcsv($stream, $headers);
                    foreach ($payload['monthly_trends'] as $row) {
                        $cells = [$row['month'], $row['bookings'], $row['trips']];
                        if ($canViewFinance) {
                            array_push($cells, $row['estimated_value'], $row['finalized_charges']);
                        }
                        fputcsv($stream, $cells);
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
