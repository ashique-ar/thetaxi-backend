<?php
// app/Http/Requests/ServiceType/UpdateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceTypeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $serviceType = $this->route('service_type');
        $context = $this->input('context', $serviceType?->context);

        if ($context === 'corporate') {
            $this->merge([
                'owner_type' => '',
                'owner_id' => '',
            ]);
        }
    }

    public function rules()
    {
        $serviceType = $this->route('service_type');
        $id = $serviceType->id;
        $context = (string) $this->input('context', $serviceType->context ?? 'portal');
        $ownerType = (string) $this->input('owner_type', $serviceType->owner_type ?? '');
        $ownerId = (string) $this->input('owner_id', $serviceType->owner_id ?? '');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('service_types', 'code')
                    ->ignore($id)
                    ->where(fn ($query) => $query
                        ->where('context', $context)
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'context' => ['sometimes', 'required', 'string', 'max:50'],
            'owner_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', 'string'],
            'trip_mode' => ['sometimes', 'required', 'string', 'in:fixed_route,open_package'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('service_types', 'slug')
                    ->ignore($id)
                    ->where(fn ($query) => $query
                        ->where('context', $context)
                        ->where('owner_type', $ownerType)
                        ->where('owner_id', $ownerId)),
            ],
            'thumbnail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pricing_mode' => ['sometimes', 'nullable', 'string', 'in:trip,day,hourly'],
            'uses_dropoff_time' => ['sometimes', 'boolean'],
            'allow_return_trip' => ['sometimes', 'boolean'],
            'frontend_category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'nullable', 'integer'],
            'is_internal' => ['sometimes', 'boolean'],
            'workflow_config' => ['sometimes', 'nullable', 'array:return_stage_enabled,qc_stage_enabled,maintenance_stage_enabled,signed_agreement_required,driver_license_required,customer_identity_document_required,booking_approval_required'],
            'workflow_config.return_stage_enabled' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.qc_stage_enabled' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.maintenance_stage_enabled' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.signed_agreement_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.driver_license_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.customer_identity_document_required' => ['sometimes', 'nullable', 'boolean'],
            'workflow_config.booking_approval_required' => ['sometimes', 'nullable', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'terms' => ['sometimes', 'nullable', 'string'],
            'minimum_km' => ['sometimes', 'nullable'],
            'default_payment_arrangement'=>['sometimes','nullable','in:cash_to_driver,online,advance_then_balance,deposit_then_balance,pay_at_end,account_credit,monthly_invoice,complimentary'],
            'deposit_mode'=>['sometimes','nullable','in:none,fixed,percentage'],'deposit_value'=>['sometimes','nullable','numeric','min:0'],'settlement_due_days'=>['sometimes','nullable','integer','min:0','max:365'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('workflow_config') && ! $this->exists('type')) return;
            $incoming = $this->input('workflow_config', []);
            if (! is_array($incoming)) return;
            $config = array_merge((array) ($this->route('service_type')?->workflow_config ?? []), $incoming);
            $returnEnabled = array_key_exists('return_stage_enabled', $config) && $config['return_stage_enabled'] !== null
                ? filter_var($config['return_stage_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            $qcEnabled = array_key_exists('qc_stage_enabled', $config) && $config['qc_stage_enabled'] !== null
                ? filter_var($config['qc_stage_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            if ($returnEnabled === false && $qcEnabled === true) {
                $validator->errors()->add('workflow_config.qc_stage_enabled', 'Enable the vehicle return step before enabling return inspection (QC).');
            }
            if (filter_var($config['driver_license_required'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && $this->input('type', $this->route('service_type')?->type) !== 'self_driven') {
                $validator->errors()->add('workflow_config.driver_license_required', 'A customer driver licence requirement can only be used for self-drive services.');
            }
            if (filter_var($config['customer_identity_document_required'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && $this->input('type', $this->route('service_type')?->type) !== 'self_driven') {
                $validator->errors()->add('workflow_config.customer_identity_document_required', 'A customer identity document requirement can only be used for self-drive services.');
            }
            $serviceType = $this->route('service_type');
            $depositMode = $this->input('deposit_mode', $serviceType?->deposit_mode);
            $depositValue = $this->input('deposit_value', $serviceType?->deposit_value);
            if ($depositMode === 'percentage' && is_numeric($depositValue) && (float) $depositValue > 100) {
                $validator->errors()->add('deposit_value', 'A service deposit percentage cannot be greater than 100%.');
            }
        });
    }
}
