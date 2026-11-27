<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TourLaunchSeeder extends Seeder
{
    private const PLACES = [
        'colombo' => ['Colombo', 'Western Province', 'City sights, museums and coastal landmarks.'],
        'negombo' => ['Negombo', 'Western Province', 'Coastal town near Bandaranaike International Airport.'],
        'kandy' => ['Kandy', 'Central Province', 'Hill city and cultural heritage sites.'],
        'dambulla' => ['Dambulla', 'Central Province', 'Cave temple and cultural triangle visits.'],
        'sigiriya' => ['Sigiriya', 'Central Province', 'Rock fortress and surrounding heritage area.'],
        'anuradhapura' => ['Anuradhapura', 'North Central Province', 'Ancient city and Buddhist heritage sites.'],
        'polonnaruwa' => ['Polonnaruwa', 'North Central Province', 'Ancient city and archaeological sites.'],
        'trincomalee' => ['Trincomalee', 'Eastern Province', 'Harbour, beaches and coastal heritage.'],
        'nuwara_eliya' => ['Nuwara Eliya', 'Central Province', 'Tea country, gardens and highland scenery.'],
        'ella' => ['Ella', 'Uva Province', 'Highland walks, viewpoints and tea country.'],
        'galle' => ['Galle', 'Southern Province', 'Fort, museums and southern coast.'],
        'bentota' => ['Bentota', 'Southern Province', 'Beaches and river activities.'],
        'mirissa' => ['Mirissa', 'Southern Province', 'Southern beaches and coastal viewpoints.'],
        'yala' => ['Yala', 'Southern Province', 'National park region and wildlife visits.'],
        'arugam_bay' => ['Arugam Bay', 'Eastern Province', 'East coast beaches and cultural sites.'],
        'jaffna' => ['Jaffna', 'Northern Province', 'Northern heritage, temples and city sights.'],
        'kalpitiya' => ['Kalpitiya', 'North Western Province', 'Coastal and lagoon area.'],
        'hikkaduwa' => ['Hikkaduwa', 'Southern Province', 'Beach and marine visits.'],
    ];

    private const ACTIVITIES = [
        'colombo' => [['National Museum of Colombo', 90], ['Gangaramaya Temple', 60]],
        'negombo' => [['Negombo Lagoon', 60], ['St. Mary’s Church, Negombo', 45]],
        'kandy' => [['Temple of the Tooth', 90], ['Royal Botanical Gardens, Peradeniya', 120]],
        'dambulla' => [['Dambulla Cave Temple', 120]],
        'sigiriya' => [['Sigiriya Rock Fortress', 180], ['Pidurangala Rock', 120]],
        'anuradhapura' => [['Ruwanwelisaya', 60], ['Sri Maha Bodhi', 60]],
        'polonnaruwa' => [['Polonnaruwa Ancient City', 180]],
        'trincomalee' => [['Koneswaram Temple', 60], ['Fort Frederick', 60]],
        'nuwara_eliya' => [['Gregory Lake', 60], ['Horton Plains National Park', 240]],
        'ella' => [['Nine Arches Bridge', 60], ['Little Adam’s Peak', 120]],
        'galle' => [['Galle Fort', 120], ['National Maritime Museum, Galle', 60]],
        'bentota' => [['Brief Garden', 90], ['Bentota Beach', 60]],
        'mirissa' => [['Coconut Tree Hill', 45], ['Weligama Bay', 60]],
        'yala' => [['Yala National Park entrance', 240]],
        'arugam_bay' => [['Kudumbigala Monastery', 120]],
        'jaffna' => [['Nallur Kandaswamy Temple', 60], ['Jaffna Fort', 60]],
        'kalpitiya' => [['Kalpitiya Lagoon', 120]],
        'hikkaduwa' => [['Hikkaduwa Coral Sanctuary', 90]],
    ];

    public function run(): void
    {
        $actorId = DB::table('users')->where('is_active', true)->orderBy('id')->value('id');
        if (!$actorId) {
            throw new RuntimeException('Create an active user before seeding Tour pricing definitions.');
        }

        DB::transaction(function () use ($actorId): void {
            $service = DB::table('service_types')->where('code', 'tours')->first();
            if ($service && (($service->context ?? null) !== 'public' || ($service->pricing_basis ?? null) !== 'vehicle_class')) {
                throw new RuntimeException('The existing "tours" service code is not a public class-priced service; resolve it before seeding Tours.');
            }
            $serviceId = $service->id ?? (string) Str::uuid();
            if (!$service) {
                DB::table('service_types')->insert([
                    'id' => $serviceId, 'code' => 'tours', 'name' => 'Tours', 'context' => 'public',
                    'slug' => 'tours', 'type' => 'with_driver', 'pricing_mode' => 'dynamic',
                    'pricing_basis' => 'vehicle_class', 'description' => 'Build a multi-day Sri Lanka tour using selected places and activities.',
                    'terms' => 'Activity visits are route stops only. Activity tickets, entry fees, supplier reservations and activity bookings are not included. Customers arrange these separately; activity booking may be offered in a future release.',
                    'is_active' => true, 'is_internal' => false, 'is_inquiry' => false,
                    'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->seedMapDrafts($serviceId, $actorId);
            $this->seedVerifiedClassesAndStartingRates($serviceId, $actorId);
            $this->seedDraftTerms($serviceId);
            $this->setSelectedServiceWithoutEnablingSales($serviceId);
        });
    }

    private function seedMapDrafts(string $serviceId, string $actorId): void
    {
        $sortOrder = 0;
        foreach (self::PLACES as $code => [$city, $province, $description]) {
            $locationId = (string) (DB::table('predefined_locations')->where('code', 'tour_'.$code)->value('id') ?? Str::uuid());
            DB::table('predefined_locations')->insertOrIgnore([
                'id' => $locationId, 'name' => $city, 'code' => 'tour_'.$code, 'type' => 'tour',
                'city' => $city, 'country' => 'Sri Lanka', 'address' => $province,
                'latitude' => 0, 'longitude' => 0, 'is_active' => false, 'sort_order' => $sortOrder,
                'description' => $description, 'metadata' => json_encode(['mapping_required' => true]),
                'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $locationId = (string) DB::table('predefined_locations')->where('code', 'tour_'.$code)->value('id');
            $destinationId = (string) (DB::table('tour_destinations')->where('service_type_id', $serviceId)->where('code', $code)->value('id') ?? Str::uuid());
            DB::table('tour_destinations')->insertOrIgnore([
                'id' => $destinationId, 'service_type_id' => $serviceId, 'predefined_location_id' => $locationId,
                'code' => $code, 'name' => $city, 'description' => $description.' Location must be mapped with Google Places before publishing.',
                'timezone' => 'Asia/Colombo', 'sort_order' => 0, 'is_published' => false,
                'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $destinationId = (string) DB::table('tour_destinations')->where('service_type_id', $serviceId)->where('code', $code)->value('id');
            foreach (self::ACTIVITIES[$code] ?? [] as $index => [$activity, $minutes]) {
                $activityCode = Str::slug($activity);
                $activityLocationCode = substr('tour_activity_'.$code.'_'.$activityCode, 0, 39).'_'.substr(sha1($activity), 0, 10);
                $activityLocationId = (string) (DB::table('predefined_locations')->where('code', $activityLocationCode)->value('id') ?? Str::uuid());
                DB::table('predefined_locations')->insertOrIgnore([
                    'id' => $activityLocationId, 'name' => $activity, 'code' => $activityLocationCode, 'type' => 'tour',
                    'city' => $city, 'country' => 'Sri Lanka', 'address' => $province, 'latitude' => 0, 'longitude' => 0,
                    'is_active' => false, 'sort_order' => $index, 'description' => 'Activity entrance must be mapped with Google Places.',
                    'metadata' => json_encode(['mapping_required' => true, 'tickets_included' => false]),
                    'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $activityLocationId = (string) DB::table('predefined_locations')->where('code', $activityLocationCode)->value('id');
                DB::table('tour_activities')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tour_destination_id' => $destinationId,
                    'predefined_location_id' => $activityLocationId, 'code' => $activityCode, 'name' => $activity,
                    'description' => 'Optional visit stop. Tickets, fees and reservations are not included.',
                    'visit_duration_minutes' => $minutes, 'sort_order' => $index, 'is_published' => false,
                    'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $sortOrder++;
        }
    }

    private function seedVerifiedClassesAndStartingRates(string $serviceId, string $actorId): void
    {
        $source = DB::table('service_types')->where('context', 'public')->where('pricing_basis', 'vehicle_group')
            ->where('is_active', true)->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', ['day rental'])->first();
        $sourceSlab = $source ? DB::table('vehicle_pricing_slab_definitions')->where('service_type_id', $source->id)
            ->where('is_active', true)->where('min_days', 1)->orderBy('max_days')->first() : null;
        if (!$sourceSlab) {
            throw new RuntimeException('No active public Day Rental 1-day slab exists; Tour starter rates were not guessed.');
        }
        $rateByGroup = DB::table('vehicle_group_pricing')->where('slab_definition_id', $sourceSlab->id)
            ->where('rate_type', 'per_day')->where('is_active', true)->whereNull('owner_type')->whereNull('owner_id')
            ->whereNotNull('vehicle_group_id')->get(['vehicle_group_id', 'rate', 'includes_fuel', 'includes_driver'])->keyBy('vehicle_group_id');
        $sourceDistanceDefinition = DB::table('vehicle_pricing_common_rate_definitions')
            ->whereIn('code', ['extra_km_rate', 'distance_rate'])->where('is_active', true)->whereNull('deleted_at')
            ->where(fn($query) => $query->where('service_type_id', $source->id)->orWhereNull('service_type_id'))
            ->orderByRaw('CASE WHEN service_type_id IS NULL THEN 1 ELSE 0 END')->first();
        if (!$sourceDistanceDefinition) {
            throw new RuntimeException('No active Day Rental extra-kilometre common rate exists; Tour distance prices were not guessed.');
        }
        $distanceRateByGroup = DB::table('vehicle_group_common_rate_pricing')
            ->where('common_rate_definition_id', $sourceDistanceDefinition->id)->where('is_active', true)->whereNull('deleted_at')
            ->whereNotNull('vehicle_group_id')->pluck('value', 'vehicle_group_id');
        $classes = [
            ['class' => 'Economy', 'display' => 'Economy car', 'passengers' => 4, 'bags' => 2, 'groups' => null],
            ['class' => 'Compact', 'display' => 'Standard car', 'passengers' => 4, 'bags' => 2, 'groups' => []],
            ['class' => 'Commercial', 'display' => 'Flat-roof van', 'passengers' => 9, 'bags' => 6, 'groups' => []],
            ['class' => '14 Seater Van (High Roof Van', 'display' => 'High-roof van', 'passengers' => 9, 'bags' => 4, 'groups' => ['Toyota KDH 14 Seater']],
            ['class' => 'Full-size', 'display' => 'Coaster bus', 'passengers' => 14, 'bags' => 8, 'groups' => ['Toyota Coaster']],
        ];
        $slabId = (string) (DB::table('vehicle_pricing_slab_definitions')->where('service_type_id', $serviceId)->where('name', 'Tours 1–60 days')->value('id') ?? Str::uuid());
        DB::table('vehicle_pricing_slab_definitions')->insertOrIgnore([
            'id' => $slabId, 'service_type_id' => $serviceId, 'name' => 'Tours 1–60 days', 'type' => 'daily',
            'min_days' => 1, 'max_days' => 60, 'max_km_per_day' => $sourceSlab->max_km_per_day,
            'sort_order' => 1, 'is_active' => true, 'created_user_id' => $actorId, 'updated_user_id' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($classes as $row) {
            $classId = DB::table('vehicle_classes')->where('name', $row['class'])->value('id');
            if (!$classId) continue;
            $groups = DB::table('vehicle_groups')->where('class_id', $classId)->where('is_active', true)->whereNull('deleted_at')
                ->whereNotNull('passengers_count')->where('passengers_count', '>=', $row['passengers'])
                ->whereNotNull('hand_luggages')->where('hand_luggages', '>=', $row['bags'])
                ->when(is_array($row['groups']), fn($query) => $query->whereIn('name', $row['groups']))
                ->get(['id', 'name']);
            $eligible = $groups->filter(fn($group) => $rateByGroup->has($group->id) && $distanceRateByGroup->has($group->id))->values();
            $mapping = $eligible->pluck('id')->all();
            $hasRates = $mapping !== [];
            $setting = DB::table('service_vehicle_class_settings')->where('service_type_id', $serviceId)->where('vehicle_class_id', $classId)->first();
            if (!$setting) {
                DB::table('service_vehicle_class_settings')->insert([
                    'id' => (string) Str::uuid(), 'service_type_id' => $serviceId, 'vehicle_class_id' => $classId,
                    'customer_display_name' => $row['display'], 'eligible_vehicle_group_ids' => json_encode($mapping),
                    'max_passengers' => $row['passengers'], 'max_luggage' => $row['bags'],
                    'description' => $hasRates ? 'Tour transport class. Activity tickets and fees are not included.' : 'Not bookable until staff verifies and maps an eligible group.',
                    'is_active' => true, 'is_hidden' => !$hasRates, 'is_inquiry_only' => !$hasRates,
                    'sort_order' => count($classes), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (!$hasRates) continue;
            $minimumGroup = $eligible->sortBy(fn($group) => (float) $rateByGroup[$group->id]->rate)->first();
            $sourcePrice = $rateByGroup[$minimumGroup->id];
            DB::table('vehicle_group_pricing')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'slab_definition_id' => $slabId, 'vehicle_group_id' => null,
                'vehicle_class_id' => $classId, 'rate' => $sourcePrice->rate, 'rate_type' => 'per_day',
                'minimum_charge' => null, 'includes_fuel' => $sourcePrice->includes_fuel, 'includes_driver' => $sourcePrice->includes_driver,
                'is_active' => true, 'owner_type' => null, 'owner_id' => null, 'priority' => 0,
                'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $distanceDefinitionId = $this->ensureTourDistanceRateDefinition($serviceId, $actorId);
            DB::table('vehicle_group_common_rate_pricing')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'vehicle_group_id' => null, 'vehicle_class_id' => $classId,
                'common_rate_definition_id' => $distanceDefinitionId,
                'value' => $eligible->min(fn($group) => (float) $distanceRateByGroup[$group->id]),
                'is_active' => true, 'owner_type' => null, 'owner_id' => null, 'priority' => 0,
                'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->seedTourCalculationBranches($serviceId, $actorId);
    }

    private function ensureTourDistanceRateDefinition(string $serviceId, string $actorId): string
    {
        $existing = DB::table('vehicle_pricing_common_rate_definitions')->where('service_type_id', $serviceId)->where('code', 'distance_rate')->first();
        if ($existing) return (string) $existing->id;
        $id = (string) Str::uuid();
        DB::table('vehicle_pricing_common_rate_definitions')->insert([
            'id' => $id, 'name' => 'Tour extra kilometre rate', 'code' => 'distance_rate',
            'service_type_id' => $serviceId, 'vehicle_group_id' => null,
            'description' => 'Starting extra-kilometre rate copied from the minimum eligible Day Rental fleet-group rate.',
            'common_rate_type' => 'per_km', 'is_mandatory' => true, 'is_active' => true, 'sort_order' => 1,
            'created_user_id' => $actorId, 'updated_user_id' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function seedTourCalculationBranches(string $serviceId, string $actorId): void
    {
        $branches = [
            [
                'name' => 'Tour daily class price', 'formula' => 'duration_days * slab_rate', 'priority' => 20,
                'variables' => [['name' => 'duration_days', 'type' => 'duration'], ['name' => 'slab_rate', 'type' => 'slab_rate']],
                'conditions' => [['field' => 'journey_distance', 'operator' => '<=', 'value' => 0, 'value_field' => 'package_included_km']],
            ],
            [
                'name' => 'Tour distance class price', 'formula' => 'journey_distance * distance_rate', 'priority' => 10,
                'variables' => [['name' => 'journey_distance', 'type' => 'distance'], ['name' => 'distance_rate', 'type' => 'common_rate']],
                'conditions' => [['field' => 'journey_distance', 'operator' => '>', 'value' => 0, 'value_field' => 'package_included_km']],
            ],
        ];
        foreach ($branches as $branch) {
            if (DB::table('vehicle_pricing_calculation_definitions')->where('service_type_id', $serviceId)->where('name', $branch['name'])->exists()) continue;
            DB::table('vehicle_pricing_calculation_definitions')->insert([
                'id' => (string) Str::uuid(), 'service_type_id' => $serviceId, 'name' => $branch['name'],
                'description' => 'Initial Tour pricing branch. Adjust slabs, included kilometres and common rate in the existing Pricing tool before enabling sales.',
                'formula' => $branch['formula'], 'variables' => json_encode($branch['variables']),
                'conditions' => json_encode($branch['conditions']), 'status' => 'active',
                'owner_type' => null, 'owner_id' => null, 'priority' => $branch['priority'],
                'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedDraftTerms(string $serviceId): void
    {
        DB::table('terms_and_conditions')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'title' => 'Tours booking terms — review before activation', 'slug' => 'tours-booking-terms-draft',
            'content' => 'DRAFT — Legal and operations review required before activation. Tour routes and distances are estimates and may change due to traffic, closures, weather or customer-approved route changes. The selected class is the purchased vehicle class; final fleet assignment is subject to verified capacity and availability. Activity stops are transport visits only. Tickets, entry fees, activity reservations and supplier charges are not included and must be arranged separately. Changes to dates, stops, waiting time or distance may change the final price under the accepted pricing rules. Payment, cancellation, overtime, overnight and driver accommodation rules must be completed and reviewed before public launch.',
            'service_type_id' => $serviceId, 'service_type' => null, 'payment_type' => null, 'version' => 1,
            'is_active' => false, 'effective_date' => null, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function setSelectedServiceWithoutEnablingSales(string $serviceId): void
    {
        $selected = DB::table('business_settings')->where('type', 'tour_service_type_id')->first();
        if (!$selected) {
            DB::table('business_settings')->insert([
                'id' => (string) Str::uuid(), 'type' => 'tour_service_type_id', 'value' => $serviceId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (!DB::table('business_settings')->where('type', 'tours_enabled')->exists()) {
            DB::table('business_settings')->insert([
                'id' => (string) Str::uuid(), 'type' => 'tours_enabled', 'value' => 'false',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
