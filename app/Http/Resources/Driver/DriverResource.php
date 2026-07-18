<?php
// app/Http/Resources/DriverResource.php
namespace App\Http\Resources\Driver;

use App\Http\Resources\CountryResource;
use App\Http\Resources\DrivingLicenseTypeResource;
use App\Http\Resources\StateResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\PaymentMethodResource;
use App\Models\State;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray($request)
    {
        $fullName = trim(($this->user?->first_name ?? '') . ' ' . ($this->user?->last_name ?? ''));
        $status = $this->availability_status ?: ($this->is_online ? 'available' : 'off_duty');
        $activeAssignment = $this->assignments()
            ->with('booking:id,booking_number')
            ->whereIn('trip_phase', ['active', 'confirmed', 'accepted', 'pickup_arrived', 'in_progress'])
            ->where('status', '!=', 'cancelled')
            ->whereHas('booking', function ($query) {
                $query->whereNotIn('status', ['completed', 'cancelled', 'inquiry_cancelled']);
            })
            ->latest('assigned_from')
            ->first();

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'code' => $this->code,
            'employee_id' => $this->code,
            'first_name' => $this->user?->first_name,
            'last_name' => $this->user?->last_name,
            'full_name' => $fullName !== '' ? $fullName : ($this->code ?? 'Driver'),
            'email' => $this->user?->email,
            'phone' => $this->user?->phone,
            'nic' => $this->nic,
            'license_no' => $this->license_no,
            'license_number' => $this->license_no,
            'license_expiry' => $this->license_expiry,
            'license_type' => $this->license_type,
            'default_vehicle_id' => $this->default_vehicle_id,
            'dob' => $this->dob,
            'address' => $this->address,
            'country_id' => $this->country_id,
            'state_id' => $this->state_id,
            'city' => $this->city,
            'remarks' => $this->remarks,
            'postal_code' => $this->postal_code,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'blood_group' => $this->blood_group,
            'medical_conditions' => $this->medical_conditions,
            'hire_date' => $this->hire_date,
            'termination_date' => $this->termination_date,
            'is_active' => $this->is_active,
            // Real-time status fields
            'is_online' => $this->is_online ?? false,
            'last_active_at' => $this->last_active_at,
            'current_latitude' => $this->current_latitude,
            'current_longitude' => $this->current_longitude,
            'current_device_uuid' => $this->current_device_uuid,
            // Availability status
            'availability_status' => $this->availability_status,
            'current_status' => [
                'status' => $status,
                'updated_at' => $this->last_active_at,
                'booking_id' => $activeAssignment?->booking_id,
                'location' => [
                    'latitude' => $this->current_latitude !== null ? (float) $this->current_latitude : null,
                    'longitude' => $this->current_longitude !== null ? (float) $this->current_longitude : null,
                ],
            ],
            'current_booking_id' => $activeAssignment?->booking_id,
            'current_booking_number' => $activeAssignment?->booking?->booking_number,
            'rating' => (float) ($this->rating ?? 0),
            'total_trips' => (int) ($this->total_trips ?? 0),
            'assigned_vehicle' => $this->whenLoaded('defaultVehicle', fn () => $this->defaultVehicle ? [
                'id' => $this->defaultVehicle->id,
                'plate_number' => $this->defaultVehicle->license_plate ?? $this->defaultVehicle->registration_no,
                'model' => $this->defaultVehicle->title,
            ] : null),
            'payment_method' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            // Relations
            'licenseType' => new DrivingLicenseTypeResource($this->whenLoaded('licenseType')),
            'state' => new StateResource($this->whenLoaded('state')),
            'country' => new CountryResource($this->whenLoaded('country')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
