<?php

namespace App\Services;

use App\Models\Service\ServiceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class HomepageVehicleSections
{
    public function __construct(
        private WebsiteSettingsService $settings,
        private VehicleService $vehicles,
    ) {}

    public function visible(): array
    {
        $configured = $this->settings->get('homepage_vehicle_sections', '[]');
        $configured = is_string($configured) ? json_decode($configured, true) : $configured;
        if (!is_array($configured)) {
            return [];
        }

        $configured = array_values(array_filter($configured, fn ($section) =>
            is_array($section) && !empty($section['enabled']) && !empty($section['service_type'])
        ));
        if (!$configured) {
            return [];
        }

        $services = ServiceType::publicContext()->active()
            ->where(fn ($query) => $query->where('is_internal', false)->orWhereNull('is_internal'))
            ->whereIn('code', array_column($configured, 'service_type'))
            ->get()->keyBy('code');
        $today = Carbon::today();
        $sections = [];

        foreach ($configured as $section) {
            $service = $services->get($section['service_type']);
            if (!$service) {
                continue;
            }

            $days = max(1, min(60, (int) ($section['duration_days'] ?? 1)));
            $params = [
                'service_type' => $service->code,
                'duration_days' => $days,
                'package_id' => $section['package_id'] ?? null,
                'package_hours' => max(0, min(720, (int) ($section['package_hours'] ?? 0))) ?: null,
                'estimated_distance_km' => max(0, min(5000, (int) ($section['estimated_distance_km'] ?? 0))) ?: null,
                'from_date' => $today->toDateString(),
                'to_date' => $today->copy()->addDays($days)->toDateString(),
                'to_time' => '09:00',
                'limit' => max(1, min(24, (int) ($section['limit'] ?? 8))),
            ];
            $cacheKey = 'homepage_vehicles_' . sha1(($this->settings->resolveCurrentCompanyId() ?? 'global') . json_encode($params));
            $result = Cache::remember($cacheKey, now()->addMinutes(2), fn () => $this->vehicles->getFeaturedVehicles($params));
            $vehicles = array_values(array_filter($result['data'], fn ($vehicle) =>
                $service->is_inquiry || (float) data_get($vehicle, 'pricing_info.total_amount', 0) > 0
            ));
            if (!$vehicles) {
                continue;
            }

            $sections[] = [
                'title' => trim((string) ($section['title'] ?? '')) ?: $service->name,
                'eyebrow' => trim((string) ($section['eyebrow'] ?? '')),
                'description' => trim((string) ($section['description'] ?? '')),
                'service_type' => $service->code,
                'duration_days' => $days,
                'package_id' => $section['package_id'] ?? null,
                'vehicles' => $vehicles,
            ];
        }

        return $sections;
    }
}
