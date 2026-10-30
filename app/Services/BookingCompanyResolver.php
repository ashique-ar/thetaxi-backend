<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Company;

final class BookingCompanyResolver
{
    public function resolve(?Booking $booking = null): ?string
    {
        if ($booking?->company_id && Company::query()->whereKey($booking->company_id)->where('is_active', true)->exists()) {
            return (string) $booking->company_id;
        }

        $request = request();
        $contextCompanyId = $request->attributes->get('business_company_id');
        if ($contextCompanyId && Company::query()->whereKey($contextCompanyId)->where('is_active', true)->exists()) {
            return (string) $contextCompanyId;
        }

        $requestCompanyId = app(WebsiteSettingsService::class)->resolveCurrentCompanyId();
        return $requestCompanyId && Company::query()->whereKey($requestCompanyId)->where('is_active', true)->exists()
            ? $requestCompanyId
            : null;
    }
}
