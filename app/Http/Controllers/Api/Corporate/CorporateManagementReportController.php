<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\CorporateReportFiltersRequest;
use App\Services\CorporateManagementAnalyticsService;
use App\Services\CorporatePortalPermission;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateManagementReportController extends Controller
{
    public function __construct(private readonly CorporateManagementAnalyticsService $analytics)
    {
        $this->middleware('permission:view_reports');
    }

    public function show(CorporateReportFiltersRequest $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->payload($request)]);
    }

    public function export(CorporateReportFiltersRequest $request, string $format): Response|StreamedResponse
    {
        abort_unless(in_array($format, ['csv', 'xls', 'pdf'], true), 404);
        $payload = $this->payload($request);
        $rows = $this->rows($payload);
        $filename = 'corporate-management-report-'.now()->format('Y-m-d');

        if ($format === 'pdf') {
            return Pdf::loadView('reports.corporate-management', compact('payload', 'rows'))
                ->download($filename.'.pdf');
        }

        if ($format === 'xls') {
            $html = view('reports.corporate-management-excel', compact('payload', 'rows'))->render();
            return response($html, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.xls"',
            ]);
        }

        return response()->streamDownload(function () use ($rows, $payload) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->safeCsvRow([
                'Travel date range',
                data_get($payload, 'period.from') ?: 'All travel dates',
                data_get($payload, 'period.to') ?: 'Present',
            ]));
            if ($payload['financial_metrics_visible'] && ($payload['operational']['unpriced_trip_count'] ?? 0) > 0) {
                fputcsv($output, $this->safeCsvRow(['Trips without a saved estimate', $payload['operational']['unpriced_trip_count']]));
            }
            if (!empty($payload['financial_periods'])) {
                fputcsv($output, $this->safeCsvRow(['Billing period totals']));
                foreach ($payload['financial_periods'] as $summary) {
                    foreach ($summary as $key => $value) {
                        if ($key === 'conversion_rates') {
                            if ($value) {
                                fputcsv($output, $this->safeCsvRow(['Frozen invoice FX rates']));
                                fputcsv($output, $this->safeCsvRow(['Source currency', 'Invoice currency', 'Invoice units per source unit', 'Rate captured from', 'Rate captured through', 'Rate source']));
                                foreach ($value as $rate) {
                                    fputcsv($output, $this->safeCsvRow([$rate['source_currency'], $rate['invoice_currency'], $rate['rate'], $rate['rate_at'], $rate['rate_until'], $rate['fx_source']]));
                                }
                            }
                            continue;
                        }
                        if ($key === 'webxpay_payments') {
                            if ($value) {
                                fputcsv($output, $this->safeCsvRow(['WebXPay invoice payments and LKR conversions']));
                                fputcsv($output, $this->safeCsvRow(['Invoice amount', 'Invoice currency', 'Gateway amount', 'Gateway currency', 'Invoice-to-LKR rate', 'Gateway-to-LKR rate', 'Rate captured at', 'Attempt status', 'Gateway reference', 'Paid at', 'Attempt created at']));
                                foreach ($value as $attempt) {
                                    fputcsv($output, $this->safeCsvRow([
                                        $attempt['invoice_amount'], $attempt['invoice_currency'], $attempt['gateway_amount'], $attempt['gateway_currency'],
                                        $attempt['fx_rate_to_lkr'], $attempt['gateway_fx_rate_to_lkr'], $attempt['fx_rate_at'], $attempt['status'],
                                        $attempt['transaction_reference'], $attempt['paid_at'], $attempt['created_at'],
                                    ]));
                                }
                            }
                            continue;
                        }
                        fputcsv($output, $this->safeCsvRow([str_replace('_', ' ', $key), $value]));
                    }
                    fputcsv($output, []);
                }
            }
            fputcsv($output, $this->safeCsvRow(['Monthly travel activity']));
            $monthlyHeaders = ['Month', 'Currency', 'Bookings', 'Trips'];
            if ($payload['financial_metrics_visible']) {
                array_push($monthlyHeaders, 'Unpriced trips', 'Estimated value', 'Finalized trip value');
            }
            fputcsv($output, $this->safeCsvRow($monthlyHeaders));
            foreach ($payload['monthly_trends'] as $month) {
                $monthlyRow = [$month['month'], $month['currency'], $month['bookings'], $month['trips']];
                if ($payload['financial_metrics_visible']) {
                    array_push($monthlyRow, $month['unpriced_trip_count'], $month['estimated_value'] ?? 'Incomplete', $month['finalized_charges']);
                }
                fputcsv($output, $this->safeCsvRow($monthlyRow));
            }
            fputcsv($output, []);
            $headers = ['Dimension', 'Label', 'Bookings', 'Trips'];
            if ($payload['financial_metrics_visible']) {
                array_push($headers, 'Unpriced trips', 'Currency', 'Estimated value', 'Finalized trip value');
            }
            fputcsv($output, $this->safeCsvRow($headers));
            foreach ($rows as $row) {
                fputcsv($output, $this->safeCsvRow($row));
            }
            fclose($output);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function payload(CorporateReportFiltersRequest $request): array
    {
        return $this->analytics->report($request->corporate_id, $request->validated(), CorporatePortalPermission::allows($request, 'view_payments'));
    }

    private function safeCsvRow(array $row): array
    {
        return array_map(fn ($value) => is_string($value)
            && preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $value) === 1
                ? "'".$value
                : $value, $row);
    }

    private function rows(array $payload): array
    {
        return collect($payload['dimensions'])->flatMap(fn (array $values, string $dimension) => collect($values)->map(function (array $row) use ($dimension, $payload) {
            $cells = [$dimension, $row['label'], $row['booking_count'], $row['trip_count']];
            if ($payload['financial_metrics_visible']) {
                array_push($cells, $row['unpriced_trip_count'], $row['currency'], $row['estimated_value'] ?? 'Incomplete', $row['finalized_charges']);
            }
            return $cells;
        }))->values()->all();
    }
}
