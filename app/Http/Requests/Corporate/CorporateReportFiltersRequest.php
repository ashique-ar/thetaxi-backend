<?php

namespace App\Http\Requests\Corporate;

use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CorporateReportFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:64'],
            'department_id' => ['nullable', 'uuid'],
            'division_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $corporateId = (string) $this->corporate_id;
            $departmentId = $this->validated('department_id');
            $divisionId = $this->validated('division_id');

            if ($departmentId && ! CorporateDepartment::query()
                ->where('corporate_id', $corporateId)
                ->whereKey($departmentId)
                ->exists()) {
                $validator->errors()->add('department_id', 'The selected department is not available for this corporate account.');
            }

            if ($divisionId && ! CorporateDivision::query()
                ->whereHas('department', fn($query) => $query->where('corporate_id', $corporateId))
                ->when($departmentId, fn($query) => $query->where('department_id', $departmentId))
                ->whereKey($divisionId)
                ->exists()) {
                $validator->errors()->add('division_id', 'The selected division is not available for this corporate account and department.');
            }
        }];
    }
}
