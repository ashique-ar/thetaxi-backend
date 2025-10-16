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
            'driver_id' => $this->driver_id,
            'booking_id' => $this->booking_id,
            'log_code' => $this->log_code,
            'log_date' => $this->log_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'start_km' => $this->start_km,
            'end_km' => $this->end_km,
            'start_image' => $this->start_image,
            'end_image' => $this->end_image,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
