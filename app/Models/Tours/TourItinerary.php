<?php

namespace App\Models\Tours;

use App\Models\BaseModel;
use App\Models\Booking\Booking;
use App\Models\Inquiry;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use App\Models\User;
use App\Models\Vehicle\VehicleClass;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TourItinerary extends BaseModel
{
    protected $table = 'tour_itineraries';

    protected $fillable = [
        'user_id',
        'service_type_id',
        'service_package_id',
        'service_package_template_version',
        'vehicle_class_id',
        'start_date',
        'duration_days',
        'adult_count',
        'child_count',
        'child_seat_count',
        'luggage_count',
        'currency',
        'pricing_context',
        'revision',
        'status',
        'review_inquiry_id',
        'custom_answers',
        'custom_fields_snapshot',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'duration_days' => 'integer',
        'adult_count' => 'integer',
        'child_count' => 'integer',
        'child_seat_count' => 'integer',
        'luggage_count' => 'integer',
        'revision' => 'integer',
        'service_package_template_version' => 'integer',
        'custom_answers' => 'array',
        'custom_fields_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(VehicleClass::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(TourItineraryDay::class)->orderBy('day_number');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(TourQuote::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewInquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'review_inquiry_id');
    }
}
