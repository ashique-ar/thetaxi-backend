<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\CorporateReportFiltersRequest;
use App\Services\CorporateManagementAnalyticsService;
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

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Dimension', 'Label', 'Bookings', 'Trips', 'Estimated value', 'Finalized charges']);
            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function payload(CorporateReportFiltersRequest $request): array
    {
        return $this->analytics->report($request->corporate_id, $request->validated(), $request->user()->can('view_payments'));
    }

    private function rows(array $payload): array
    {
        return collect($payload['dimensions'])->flatMap(fn (array $values, string $dimension) => collect($values)->map(fn (array $row) => [
            $dimension, $row['label'], $row['booking_count'], $row['trip_count'], $row['estimated_value'], $row['finalized_charges'],
        ]))->values()->all();
    }
}
