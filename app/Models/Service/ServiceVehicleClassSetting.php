<?php

namespace App\Models\Service;

use App\Models\BaseModel;
use App\Models\Vehicle\VehicleClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceVehicleClassSetting extends BaseModel
{
    protected $fillable = [
        'service_type_id', 'vehicle_class_id', 'max_passengers', 'max_luggage',
        'description', 'is_active', 'is_hidden', 'is_inquiry_only', 'sort_order',
        'allowed_upgrade_class_ids', 'allowed_substitute_class_ids',
        'created_user_id', 'updated_user_id',
    ];

    protected $casts = [
        'max_passengers' => 'integer', 'max_luggage' => 'integer', 'sort_order' => 'integer',
        'is_active' => 'boolean', 'is_hidden' => 'boolean', 'is_inquiry_only' => 'boolean',
        'allowed_upgrade_class_ids' => 'array', 'allowed_substitute_class_ids' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            Validator::make(array_replace($setting->getAttributes(), [
                'allowed_upgrade_class_ids' => $setting->allowed_upgrade_class_ids,
                'allowed_substitute_class_ids' => $setting->allowed_substitute_class_ids,
            ]), [
                'max_passengers' => ['required', 'integer', 'min:1'],
                'max_luggage' => ['required', 'integer', 'min:0'],
                'allowed_upgrade_class_ids' => ['nullable', 'array'],
                'allowed_upgrade_class_ids.*' => ['string', 'distinct', 'exists:vehicle_classes,id'],
                'allowed_substitute_class_ids' => ['nullable', 'array'],
                'allowed_substitute_class_ids.*' => ['string', 'distinct', 'exists:vehicle_classes,id'],
            ])->validate();
            if ($setting->serviceType?->pricing_basis !== 'vehicle_class') {
                throw ValidationException::withMessages(['service_type_id' => 'Class sales settings require a class-priced service.']);
            }
            foreach (['allowed_upgrade_class_ids', 'allowed_substitute_class_ids'] as $field) {
                if (in_array($setting->vehicle_class_id, $setting->{$field} ?? [], true)) {
                    throw ValidationException::withMessages([$field => 'Choose other classes; the purchased class is already eligible.']);
                }
            }
        });
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function allowsOperationalClass(?string $classId): bool
    {
        return $classId !== null && ($classId === $this->vehicle_class_id
            || in_array($classId, $this->allowed_substitute_class_ids ?? [], true));
    }
}
