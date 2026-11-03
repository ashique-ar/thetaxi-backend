<?php

namespace App\Services\Tours;

use App\Models\Booking\Booking;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use App\Models\Service\ServiceVehicleClassSetting;
use App\Models\Tours\TourItinerary;
use App\Models\Tours\TourQuote;
use App\Services\BookingFlowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TourBookingConversionService
{
    public function __construct(private readonly BookingFlowService $bookingFlow)
    {
    }

    public function convert(TourItinerary $itinerary, string $quoteId, string $customerId, bool $customerPortal = false, bool $publicWebsite = false): Booking
    {
        return DB::transaction(function () use ($itinerary, $quoteId, $customerId, $customerPortal, $publicWebsite): Booking {
            $locked = TourItinerary::query()->whereKey($itinerary->id)->lockForUpdate()->firstOrFail();
            if ($locked->booking_id) {
                return Booking::query()->findOrFail($locked->booking_id);
            }

            $quote = $locked->quotes()->lockForUpdate()->findOrFail($quoteId);
            if (
                $locked->status !== 'accepted' || $quote->status !== 'accepted'
                || (int) $quote->itinerary_revision !== (int) $locked->revision
            ) {
                abort(409, 'Accept the current Tour quote before creating a booking.');
            }

            $service = (($customerPortal || $publicWebsite) ? ServiceType::publicContext() : ServiceType::query())
                ->where('is_active', true)->findOrFail($locked->service_type_id);
            abort_unless($service->is_active && $service->pricing_basis === 'vehicle_class' && $service->tour_booking_enabled, 404);
            $classSetting = ServiceVehicleClassSetting::query()
                ->where('service_type_id', $service->id)
                ->where('vehicle_class_id', $locked->vehicle_class_id)
                ->where('is_active', true)
                ->where('is_hidden', false)
                ->first();
            if (!$classSetting || $classSetting->is_inquiry_only) {
                throw ValidationException::withMessages(['vehicle_class_id' => 'This class is no longer available for instant booking.']);
            }
            if ($locked->service_package_id) {
                $package = ServicePackage::query()
                    ->where('service_type_id', $service->id)
                    ->where('is_active', true)
                    ->find($locked->service_package_id);
                $defaultSetting = $package ? ServiceVehicleClassSetting::query()
                    ->where('service_type_id', $service->id)
                    ->where('vehicle_class_id', $package->default_vehicle_class_id)
                    ->where('is_active', true)
                    ->where('is_hidden', false)
                    ->first() : null;
                $allowedUpgrades = $package?->allowed_upgrade_class_ids ?? [];
                if (!$package || !$defaultSetting
                    || array_diff($allowedUpgrades, $defaultSetting->allowed_upgrade_class_ids ?? []) !== []
                    || !in_array($locked->vehicle_class_id, array_merge([$package->default_vehicle_class_id], $allowedUpgrades), true)) {
                    throw ValidationException::withMessages(['service_package_id' => 'The selected package no longer includes this vehicle class.']);
                }
            }

            $locked->load('days.stops.activity', 'days.stops.destination');
            $firstDay = $locked->days->first();
            $lastDay = $locked->days->last();
            if (!$firstDay?->start_location_snapshot || !$lastDay?->end_location_snapshot) {
                throw ValidationException::withMessages(['days' => 'The accepted itinerary is missing its start or end location.']);
            }

            $fromDate = $locked->start_date->toDateString();
            $toDate = $locked->start_date->copy()->addDays($locked->duration_days - 1)->toDateString();
            $partySize = (int) $locked->adult_count + (int) $locked->child_count;
            if ((int) $locked->child_seat_count > (int) $locked->child_count) {
                throw ValidationException::withMessages(['child_seat_count' => 'Child seats cannot exceed the number of children travelling.']);
            }
            $routeSnapshot = $quote->route_snapshot;
            $daysSnapshot = $locked->days->map(function ($day) use ($routeSnapshot): array {
                $routeDay = collect(data_get($routeSnapshot, 'days', []))->firstWhere('day_number', (int) $day->day_number);
                $schedule = collect($routeDay['schedule'] ?? [])->keyBy('stop_id');

                return [
                    'day_number' => $day->day_number,
                    'service_date' => $day->service_date,
                    'timezone' => $day->timezone,
                    'start_time' => substr((string) $day->start_time, 0, 5),
                    'start_location' => $day->start_location_snapshot,
                    'end_location' => $day->end_location_snapshot,
                    'overnight_location' => $day->overnight_destination_id,
                    'stops' => $day->stops->map(function ($stop) use ($schedule): array {
                        $timing = $schedule->get((string) $stop->id, []);

                        return [
                            'id' => (string) $stop->id,
                            'kind' => $stop->kind,
                            'name' => $timing['stop_name'] ?? $stop->activity?->name ?? $stop->destination?->name ?? data_get($stop->location_snapshot, 'name'),
                            'location' => $stop->location_snapshot,
                            'visit_duration_minutes' => $stop->visit_duration_minutes,
                            'arrival_time' => $stop->arrival_time ? substr((string) $stop->arrival_time, 0, 5) : null,
                            'estimated_arrival' => $timing['estimated_arrival'] ?? null,
                            'opens_at' => $timing['opens_at'] ?? null,
                            'closes_at' => $timing['closes_at'] ?? null,
                            'is_locked' => (bool) $stop->is_locked,
                            'activity_id' => $stop->tour_activity_id,
                            'destination_id' => $stop->tour_destination_id,
                        ];
                    })->values()->all(),
                ];
            })->values()->all();

            $booking = $this->bookingFlow->confirmBooking([
                'service_type_id' => $locked->service_type_id,
                'customer_id' => $customerId,
                ...(($customerPortal || $publicWebsite) ? ['payment_responsibility' => 'customer', 'defer_confirmation' => true] : []),
                'send_confirmation_sms' => false,
                'send_confirmation_emails' => false,
                'notify_internal_team' => false,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'from_time' => '00:00',
                'to_time' => '23:59',
                'currency' => $quote->currency,
                'passenger_count' => $partySize,
                'luggage_count' => (int) $locked->luggage_count,
                'duration_days' => (int) $locked->duration_days,
                'duration_minutes' => (int) $locked->duration_days * 1440,
                'journey_distance' => (float) $quote->distance_km,
                'total_distance' => (float) $quote->distance_km,
                'pickup_location' => $firstDay->start_location_snapshot,
                'dropoff_location' => $lastDay->end_location_snapshot,
                'pricing_context' => $locked->pricing_context,
                '_tour_itinerary_id' => $locked->id,
                '_trusted_tour_quote' => $quote,
                'booking_items' => [
                    [
                        'id' => 'tour-' . $locked->id,
                        'service_type_id' => $locked->service_type_id,
                        'service_package_id' => $locked->service_package_id,
                        'vehicle_class_id' => $locked->vehicle_class_id,
                        'pricing_basis' => 'vehicle_class',
                        'from_date' => $fromDate,
                        'to_date' => $toDate,
                        'from_time' => '00:00',
                        'to_time' => '23:59',
                        'pickup_location' => $firstDay->start_location_snapshot,
                        'dropoff_location' => $lastDay->end_location_snapshot,
                        'journey_distance' => (float) $quote->distance_km,
                        'total_distance' => (float) $quote->distance_km,
                        'duration_days' => (int) $locked->duration_days,
                        'duration_minutes' => (int) $locked->duration_days * 1440,
                        'metadata' => [
                            'tour_itinerary_id' => $locked->id,
                            'tour_itinerary_revision' => (int) $locked->revision,
                            'tour_route_snapshot' => $routeSnapshot,
                            'tour_days_snapshot' => $daysSnapshot,
                            'tour_custom_answers' => $locked->custom_answers ?? [],
                            'tour_child_seat_count' => (int) $locked->child_seat_count,
                            'tour_custom_fields_snapshot' => $locked->custom_fields_snapshot ?? [],
                            'activity_entry_note' => 'Activity visits are included in your route only. Tickets, entry fees and activity reservations are not included. Please arrange these separately. Activity booking will be introduced in a future release.',
                        ],
                    ]
                ],
            ]);

            $locked->booking_id = $booking->id;
            $locked->status = 'converted';
            $locked->save();

            return $booking;
        }, 3);
    }
}
