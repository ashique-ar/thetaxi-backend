<?php
// app/Http/Requests/ServiceType/UpdateServiceTypeRequest.php
namespace App\Http\Requests\ServiceType;

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
            'is_active' => ['sometimes', 'boolean'],
            'terms' => ['sometimes', 'nullable', 'string'],
            'minimum_km' => ['sometimes', 'nullable'],
            'default_payment_arrangement'=>['sometimes','nullable','in:cash_to_driver,online,advance_then_balance,deposit_then_balance,pay_at_end,account_credit,monthly_invoice,complimentary'],
            'deposit_mode'=>['sometimes','nullable','in:none,fixed,percentage'],'deposit_value'=>['sometimes','nullable','numeric','min:0'],'settlement_due_days'=>['sometimes','nullable','integer','min:0','max:365'],
        ];
    }
}
