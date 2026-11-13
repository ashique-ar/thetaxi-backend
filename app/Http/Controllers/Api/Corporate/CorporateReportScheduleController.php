<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateReportDeliveryRun;
use App\Models\Corporate\CorporateReportSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorporateReportScheduleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:schedule_reports');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => CorporateReportSchedule::where('corporate_id', $request->corporate_id)->orderBy('name')->get()->map(fn (CorporateReportSchedule $schedule) => $this->present($schedule)),
            'delivery_enabled' => config('corporate_portal.scheduled_reports_enabled'),
        ]);
    }

    public function runs(Request $request, string $schedule): JsonResponse
    {
        $page = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        CorporateReportSchedule::withTrashed()->where('corporate_id', $request->corporate_id)->findOrFail($schedule);
        $runs = CorporateReportDeliveryRun::query()
            ->where('corporate_id', $request->corporate_id)
            ->where('schedule_id', $schedule)
            ->orderByDesc('started_at')
            ->paginate($page['per_page'] ?? 10, ['*'], 'page', $page['page'] ?? 1);

        return response()->json([
            'status' => 'success',
            'data' => collect($runs->items())->map(fn (CorporateReportDeliveryRun $run) => [
                'id' => $run->id,
                'schedule_name' => $run->schedule_name,
                'format' => $run->format,
                'period_start' => $run->period_start?->toDateString(),
                'period_end' => $run->period_end?->toDateString(),
                'filters' => $run->filter_snapshot,
                'recipients' => $run->recipient_snapshot,
                'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'row_count' => $run->row_count,
                'artifact_bytes' => $run->artifact_bytes,
                'artifact_sha256' => $run->artifact_sha256,
                'error_summary' => $run->error_summary,
            ])->values(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('corporate_portal.scheduled_reports_enabled'), 503, 'Scheduled report delivery is not enabled.');
        $data = $this->validated($request);
        $schedule = CorporateReportSchedule::create($data + ['corporate_id' => $request->corporate_id, 'created_by' => $request->user()->id]);
        return response()->json(['status' => 'success', 'data' => $this->present($schedule)], 201);
    }

    public function update(Request $request, string $schedule): JsonResponse
    {
        $record = CorporateReportSchedule::where('corporate_id', $request->corporate_id)->findOrFail($schedule);
        $record->update($this->validated($request, $record->id));
        return response()->json(['status' => 'success', 'data' => $this->present($record->fresh())]);
    }

    public function destroy(Request $request, string $schedule): JsonResponse
    {
        CorporateReportSchedule::where('corporate_id', $request->corporate_id)->findOrFail($schedule)->delete();
        return response()->json(['status' => 'success']);
    }

    private function validated(Request $request, ?string $ignore = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('corporate_report_schedules')->where('corporate_id', $request->corporate_id)->ignore($ignore)],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'delivery_day' => ['required', 'integer', 'between:1,28'],
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email:rfc'],
            'filters' => ['nullable', 'array'],
            'filters.department_id' => ['nullable', 'uuid'],
            'filters.division_id' => ['nullable', 'uuid'],
            'filters.employee_id' => ['nullable', 'uuid'],
            'filters.status' => ['nullable', 'string', 'max:64'],
            'is_active' => ['required', 'boolean'],
        ]);
        $filters = $data['filters'] ?? [];
        $corporateId = (string) $request->corporate_id;
        $errors = [];
        if (!empty($filters['department_id']) && !CorporateDepartment::query()
            ->where('corporate_id', $corporateId)->whereKey($filters['department_id'])->exists()) {
            $errors['filters.department_id'][] = 'The selected department is not available for this corporate account.';
        }
        if (!empty($filters['division_id']) && !CorporateDivision::query()
            ->whereHas('department', fn ($query) => $query->where('corporate_id', $corporateId))
            ->when(!empty($filters['department_id']), fn ($query) => $query->where('department_id', $filters['department_id']))
            ->whereKey($filters['division_id'])->exists()) {
            $errors['filters.division_id'][] = 'The selected division is not available for this corporate account and department.';
        }
        if (!empty($filters['employee_id']) && !CorporateEmployee::query()
            ->where('corporate_id', $corporateId)->where('user_id', $filters['employee_id'])->exists()) {
            $errors['filters.employee_id'][] = 'The selected employee is not available for this corporate account.';
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $filters['labels'] = array_filter([
            'department' => !empty($filters['department_id'])
                ? CorporateDepartment::query()->where('corporate_id', $corporateId)->whereKey($filters['department_id'])->value('name') : null,
            'division' => !empty($filters['division_id'])
                ? CorporateDivision::query()->whereKey($filters['division_id'])->value('name') : null,
            'employee' => !empty($filters['employee_id'])
                ? CorporateEmployee::query()->where('corporate_id', $corporateId)->where('user_id', $filters['employee_id'])
                    ->with('user:id,first_name,last_name')->first()?->user?->full_name : null,
        ]);
        $data['filters'] = $filters;

        return $data;
    }

    private function present(CorporateReportSchedule $schedule): array
    {
        $failed = $schedule->last_failed_at
            && (!$schedule->last_delivered_at || $schedule->last_failed_at->gt($schedule->last_delivered_at));

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'format' => $schedule->format,
            'delivery_day' => $schedule->delivery_day,
            'recipients' => $schedule->recipients,
            'filters' => $schedule->filters ?? [],
            'is_active' => $schedule->is_active,
            'last_delivered_at' => $schedule->last_delivered_at?->toIso8601String(),
            'last_failed_at' => $schedule->last_failed_at?->toIso8601String(),
            'delivery_status' => !$schedule->is_active ? 'paused' : ($failed ? 'failed' : ($schedule->last_delivered_at ? 'delivered' : 'pending')),
        ];
    }
}
