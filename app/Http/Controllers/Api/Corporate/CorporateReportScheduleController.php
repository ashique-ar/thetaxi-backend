<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\CorporateReportSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CorporateReportScheduleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:schedule_reports');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => CorporateReportSchedule::where('corporate_id', $request->corporate_id)->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $schedule = CorporateReportSchedule::create($data + ['corporate_id' => $request->corporate_id, 'created_by' => $request->user()->id]);
        return response()->json(['status' => 'success', 'data' => $schedule], 201);
    }

    public function update(Request $request, string $schedule): JsonResponse
    {
        $record = CorporateReportSchedule::where('corporate_id', $request->corporate_id)->findOrFail($schedule);
        $record->update($this->validated($request, $record->id));
        return response()->json(['status' => 'success', 'data' => $record->fresh()]);
    }

    public function destroy(Request $request, string $schedule): JsonResponse
    {
        CorporateReportSchedule::where('corporate_id', $request->corporate_id)->findOrFail($schedule)->delete();
        return response()->json(['status' => 'success']);
    }

    private function validated(Request $request, ?string $ignore = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('corporate_report_schedules')->where('corporate_id', $request->corporate_id)->ignore($ignore)],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'delivery_day' => ['required', 'integer', 'between:1,28'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email:rfc'],
            'filters' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
