<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureTourSchemaReady
{
    private const REQUIRED_MIGRATIONS = [
        '2026_11_02_000001_add_class_targets_to_vehicle_pricing',
        '2026_11_02_000002_add_service_class_settings_and_purchased_booking_class',
        '2026_11_02_000003_create_tour_catalogue',
        '2026_11_02_000004_create_tour_itineraries_and_quotes',
        '2026_11_02_000005_link_tour_itinerary_to_booking',
        '2026_11_02_000006_add_tour_day_start_time',
        '2026_11_02_000007_add_tour_templates_to_service_packages',
        '2026_11_02_000008_version_tour_package_templates',
        '2026_11_02_000009_add_tour_quote_terms_evidence',
        '2026_11_02_000010_add_configured_fields_to_tour_itineraries',
        '2026_11_02_000011_add_review_inquiry_to_tour_itineraries',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $ready = DB::table('migrations')
                ->whereIn('migration', self::REQUIRED_MIGRATIONS)
                ->count() === count(self::REQUIRED_MIGRATIONS);
        } catch (\Throwable) {
            return response('Tours are temporarily unavailable.', 503);
        }

        if (! $ready) {
            return response('', 404);
        }

        return $next($request);
    }
}
