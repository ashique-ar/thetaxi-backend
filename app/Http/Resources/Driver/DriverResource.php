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
        $driverDocuments = $this->relationLoaded('documents') ? $this->documents : collect();
        $profilePhoto = $this->relationLoaded('profilePhotoDocument') ? $this->profilePhotoDocument : null;
        $vehicle = $this->relationLoaded('defaultVehicle') ? $this->defaultVehicle : null;
        $vehicleGroup = $vehicle?->relationLoaded('group') ? $vehicle->group : null;

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
            'license_issued_at' => $this->license_issued_at,
            'license_reminder_days' => $this->license_reminder_days,
            'license_status' => !$this->license_expiry ? 'missing' : ($this->license_expiry->isPast() ? 'expired' : ($this->license_expiry->diffInDays(now()) <= ($this->license_reminder_days ?? 30) ? 'expiring' : 'valid')),
            'license_renewals' => $this->whenLoaded('licenseRenewals'),
            'license_type' => $this->license_type,
            'default_vehicle_id' => $this->default_vehicle_id,
            // Keep the explicit image aliases in the mobile contract. Older
            // clients use profile_photo_* while account screens commonly use
            // profile_image_*.
            'profile_image_url' => $profilePhoto ? $this->documentResourceUrl($profilePhoto) : null,
            'profile_image' => $profilePhoto ? $this->documentPayload($profilePhoto) : null,
            'profile_photo_url' => $profilePhoto ? $this->documentResourceUrl($profilePhoto) : null,
            'profile_photo' => $profilePhoto ? $this->documentPayload($profilePhoto) : null,
            'documents' => $driverDocuments->map(fn ($document) => $this->documentPayload($document))->values(),
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
            'rating' => (float) ($this->average_rating ?? 0),
            'total_trips' => (int) ($this->total_trips ?? 0),
            'assigned_vehicle' => $vehicle ? $this->vehiclePayload($vehicle, $vehicleGroup) : null,
            'vehicle' => $vehicle ? $this->vehiclePayload($vehicle, $vehicleGroup) : null,
            'payment_method' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            // Relations
            'licenseType' => new DrivingLicenseTypeResource($this->whenLoaded('licenseType')),
            'state' => new StateResource($this->whenLoaded('state')),
            'country' => new CountryResource($this->whenLoaded('country')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }

    private function vehiclePayload($vehicle, $group): array
    {
        $documents = $vehicle->relationLoaded('documents') ? $vehicle->documents : collect();
        $make = $vehicle->relationLoaded('make') ? $vehicle->make : $group?->make;
        $model = $vehicle->relationLoaded('model') ? $vehicle->model : $group?->model;

        return [
            'id' => $vehicle->id,
            'title' => $vehicle->title,
            'registration_no' => $vehicle->registration_no ?? $vehicle->license_plate,
            'license_plate' => $vehicle->license_plate ?? $vehicle->registration_no,
            'model_year' => $vehicle->model_year,
            'registration_year' => $vehicle->year,
            'color' => $vehicle->color,
            'ownership_type' => $vehicle->ownership_type,
            'is_active' => (bool) $vehicle->is_active,
            'availability_status' => $vehicle->availability_status,
            'vehicle_group_id' => $vehicle->vehicle_group_id,
            'vehicle_group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
            'make' => $make ? ['id' => $make->id, 'name' => $make->name] : null,
            'model' => $model ? ['id' => $model->id, 'name' => $model->name] : null,
            'thumbnail' => $vehicle->thumbnail,
            'images' => $vehicle->actual_vehicle_images ?? [],
            'documents' => $documents->map(fn ($document) => $this->documentPayload($document))->values(),
        ];
    }

    private function documentPayload($document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->document_type,
            'file_name' => $document->file_name,
            'mime_type' => $document->file_type,
            'file_size' => $document->file_size,
            'status' => $document->status,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'url' => $this->documentResourceUrl($document),
            'resource_url' => $this->documentResourceUrl($document),
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }

    private function documentResourceUrl($document): ?string
    {
        return $document->resourceUrl();
    }
}
