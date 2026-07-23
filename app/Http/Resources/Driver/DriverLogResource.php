<?php
// app/Http/Resources/DriverLogResource.php
namespace App\Http\Resources\Driver;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverLogResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'log_sheet_number' => $this->log_code ?: $this->id,
            'driver_id' => $this->driver_id,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at,
            'correction_notes' => $this->correction_notes,
            'revision_number' => $this->revision_number,
            'booking_id' => $this->booking_id,
            'log_code' => $this->log_code,
            'log_date' => $this->log_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'start_km' => $this->start_km,
            'end_km' => $this->end_km,
            'total_km' => $this->total_km,
            'vehicle_id' => $this->booking?->primaryItem()?->vehicle_id,
            'vehicle_group_id' => $this->booking?->primaryItem()?->vehicle_group_id,
            'start_image' => $this->start_image,
            'end_image' => $this->end_image,
            'particulars' => $this->particulars,
            'entry_source' => $this->entry_source,
            'attachments' => $this->attachments ?? [],
            'status' => $this->status,
            'verification_status' => $this->status === 'pending' ? 'pending' : $this->status,
            'verification_notes' => $this->verification_notes,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at,
            'calculated_allowance' => 0,
            'approved_allowance' => null,
            'driver' => $this->whenLoaded('driver'),
            'booking' => $this->whenLoaded('booking'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
