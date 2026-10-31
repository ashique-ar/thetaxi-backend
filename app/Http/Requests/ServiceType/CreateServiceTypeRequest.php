<?php
// app/Http/Requests/ServiceType/CreateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

use App\Models\Service\ServiceType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateServiceTypeRequest extends FormRequest
{
    public function rules()
    {
        $context = (string) $this->input('context', 'portal');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('service_types', 'code')->where(fn ($query) => $query->where('context', $context)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'context' => ['sometimes', 'required', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'trip_mode' => ['sometimes', 'required', 'string', 'in:fixed_route,open_package'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('service_types', 'slug')->where(fn ($query) => $query->where('context', $context)),
            ],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'pricing_mode' => ['nullable', 'string', 'in:trip,day,hourly'],
            'uses_dropoff_time' => ['nullable', 'boolean'],
            'allow_return_trip' => ['nullable', 'boolean'],
            'frontend_category' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer'],
            'is_internal' => ['nullable', 'boolean'],
            'workflow_config' => ['nullable', 'array:return_stage_enabled,qc_stage_enabled,maintenance_stage_enabled,signed_agreement_required,driver_license_required,customer_identity_document_required,booking_approval_required,handover_checklist_required'],
            'workflow_config.return_stage_enabled' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.qc_stage_enabled' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.maintenance_stage_enabled' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.signed_agreement_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.driver_license_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.customer_identity_document_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.booking_approval_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.handover_checklist_required' => ['sometimes', 'nullable', 'boolean'],
            'terms' => ['nullable', 'string'],
            'minimum_km' => ['sometimes', 'nullable'],
            'default_payment_arrangement'=>['nullable','in:cash_to_driver,online,advance_then_balance,deposit_then_balance,pay_at_end,account_credit,monthly_invoice,complimentary'],
            'deposit_mode'=>['nullable','in:none,fixed,percentage'],'deposit_value'=>['nullable','numeric','min:0'],'settlement_due_days'=>['nullable','integer','min:0','max:365'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $config = $this->input('workflow_config', []);
            if (! is_array($config)) return;
            $returnEnabled = array_key_exists('return_stage_enabled', $config) && $config['return_stage_enabled'] !== null
                ? filter_var($config['return_stage_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            $qcEnabled = array_key_exists('qc_stage_enabled', $config) && $config['qc_stage_enabled'] !== null
                ? filter_var($config['qc_stage_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if ($returnEnabled === false && $qcEnabled === true) {
                $validator->errors()->add('workflow_config.qc_stage_enabled', 'Enable the vehicle return step before enabling return inspection (QC).');
            }
            if (filter_var($config['driver_license_required'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && ! ServiceType::isSelfDriveType($this->input('type')) ) {
                $validator->errors()->add('workflow_config.driver_license_required', 'A customer driver licence requirement can only be used for self-drive services.');
            }
            if (filter_var($config['customer_identity_document_required'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && ! ServiceType::isSelfDriveType($this->input('type')) ) {
                $validator->errors()->add('workflow_config.customer_identity_document_required', 'A customer identity document requirement can only be used for self-drive services.');
            }
            if ($this->input('deposit_mode') === 'percentage' && is_numeric($this->input('deposit_value'))
                && (float) $this->input('deposit_value') > 100) {
                $validator->errors()->add('deposit_value', 'A service deposit percentage cannot be greater than 100%.');
            }
        });
    }
}
