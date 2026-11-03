<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Tours\TourCatalogueController;
use App\Http\Controllers\Website\TourController as WebsiteTourController;
use App\Http\Controllers\Api\Service\ServicePackageController;
use App\Models\PredefinedLocation;
use App\Models\Service\ServiceType;
use App\Models\Service\ServicePackage;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\Tours\TourRoutePlanner;
use App\Services\WebsiteSettingsService;
use App\Services\AIContentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Pricing\PricingContextPolicyService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TourCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        activity()->disableLogging();
        Schema::create('service_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('context')->default('public');
            $table->string('pricing_basis')->default('vehicle_group');
            $table->boolean('tour_booking_enabled')->default(false);
            $table->json('form_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('predefined_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('type')->default('general');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Sri Lanka');
            $table->decimal('latitude', 10, 6);
            $table->decimal('longitude', 10, 6);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->uuid('vehicle_class_id');
            $table->integer('max_passengers')->default(3);
            $table->integer('max_luggage')->default(3);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_inquiry_only')->default(false);
            $table->json('allowed_upgrade_class_ids')->nullable();
            $table->json('allowed_substitute_class_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('default_vehicle_class_id')->nullable();
            $table->json('allowed_upgrade_class_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('tour_itineraries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        $this->migration()->up();
        (require database_path('migrations/2026_11_02_000007_add_tour_templates_to_service_packages.php'))->up();
        (require database_path('migrations/2026_11_02_000008_version_tour_package_templates.php'))->up();
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert(collect([
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
            '2026_11_03_000001_add_child_seat_count_to_tour_itineraries',
        ])->map(fn (string $migration) => ['migration' => $migration, 'batch' => 1])->all());
        DB::table('service_types')->insert([
            'id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'name' => 'Tours',
            'pricing_basis' => 'vehicle_class',
            'tour_booking_enabled' => true,
        ]);
    }

    public function test_published_tour_services_expose_only_customer_selection_details_when_sales_are_enabled(): void
    {
        $settings = \Mockery::mock(WebsiteSettingsService::class);
        $settings->shouldReceive('get')->with('tour_ai_itinerary_enabled', false)->andReturn(false);
        app()->instance(WebsiteSettingsService::class, $settings);
        $serviceId = 'c2c58e96-7a3d-4974-81b8-2f6fef280947';
        $classId = 'ad150b30-e73c-43c8-8b46-884c5297caf9';
        DB::table('vehicle_classes')->insert(['id' => $classId, 'name' => 'Standard car', 'description' => 'Room for three.']);
        DB::table('service_vehicle_class_settings')->insert([
            'id' => 'a3f22d30-1d56-4d65-8805-5d30c59b3287', 'service_type_id' => $serviceId,
            'vehicle_class_id' => $classId, 'max_passengers' => 3, 'max_luggage' => 2,
        ]);
        DB::table('service_packages')->insert([
            'id' => '581ac347-a5d6-4b32-a72b-16d6512ed427', 'service_type_id' => $serviceId,
            'name' => 'Classic', 'default_vehicle_class_id' => $classId, 'allowed_upgrade_class_ids' => json_encode([]),
            'tour_itinerary_template' => json_encode(['days' => [['day_number' => 1, 'stops' => []]]]),
        ]);
        DB::table('service_types')->insert([
            'id' => '50f31c02-93e5-4fb7-bb5a-a70a53b88f44', 'name' => 'Internal Tours',
            'context' => 'portal', 'pricing_basis' => 'vehicle_class', 'tour_booking_enabled' => true,
        ]);
        DB::table('service_types')->where('id', $serviceId)->update(['form_config' => json_encode(['fields' => [
            'pickup_note' => ['type' => 'text', 'label' => 'Pickup note', 'required' => true],
            'google_place' => ['type' => 'location', 'label' => 'Arbitrary location'],
        ]])]);

        $response = (new TourCatalogueController)->publishedServices()->getData(true);

        self::assertCount(1, $response['data']['services']);
        self::assertSame('Tours', $response['data']['services'][0]['name']);
        self::assertSame($classId, $response['data']['services'][0]['classes'][0]['id']);
        self::assertSame(3, $response['data']['services'][0]['classes'][0]['max_passengers']);
        self::assertSame(1, $response['data']['services'][0]['packages'][0]['tour_itinerary_template']['days'][0]['day_number']);
        self::assertSame('Classic', $response['data']['services'][0]['packages'][0]['name']);
        self::assertFalse($response['data']['services'][0]['ai_itinerary_helper_enabled']);
        self::assertSame('pickup_note', $response['data']['services'][0]['form_fields'][0]['name']);
        self::assertCount(1, $response['data']['services'][0]['form_fields']);
        self::assertArrayNotHasKey('tour_booking_enabled', $response['data']['services'][0]);
        $this->getJson('/api/public/tours/catalogue?service_type_id=50f31c02-93e5-4fb7-bb5a-a70a53b88f44')
            ->assertNotFound();

        $enabledSettings = \Mockery::mock(WebsiteSettingsService::class);
        $enabledSettings->shouldReceive('get')->with('tour_ai_itinerary_enabled', false)->andReturn(true);
        app()->instance(WebsiteSettingsService::class, $enabledSettings);
        $enabled = (new TourCatalogueController)->publishedServices()->getData(true);
        self::assertTrue($enabled['data']['services'][0]['ai_itinerary_helper_enabled']);
    }

    public function test_customer_and_staff_tour_catalogue_endpoints_are_distinct(): void
    {
        $routes = app('router')->getRoutes();
        $public = $routes->match(Request::create('/api/tours/catalogue', 'GET'));
        $staff = $routes->match(Request::create('/api/tours/manage-catalogue', 'GET'));

        $public = collect($routes->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), 'public/tours/catalogue'));
        $staff = collect($routes->getRoutes())->first(fn ($route) => str_ends_with($route->uri(), 'tours/manage-catalogue'));

        self::assertSame(TourCatalogueController::class.'@published', $public?->getActionName());
        self::assertSame(TourCatalogueController::class.'@manageIndex', $staff?->getActionName());
    }

    public function test_public_tour_planner_is_a_laravel_blade_page(): void
    {
        $view = app(WebsiteTourController::class)->index();

        self::assertSame('tours.index', $view->name());
        $routes = collect(app('router')->getRoutes()->getRoutes());
        self::assertTrue($routes->contains(
            fn ($route) => $route->uri() === 'tours' && $route->getName() === 'tours.index'
        ));
        foreach ([
            'tours.drafts.store',
            'tours.drafts.route',
            'tours.drafts.quote',
            'tours.drafts.accept',
            'tours.drafts.booking',
            'tours.drafts.request-review',
            'tours.confirmation',
        ] as $routeName) {
            self::assertTrue($routes->contains(fn ($route) => $route->getName() === $routeName), "Missing Tour website route {$routeName}.");
        }
    }

    public function test_package_template_allows_activity_access_point_without_parent_stop_and_rejects_unpublished_destinations(): void
    {
        $serviceId = 'c2c58e96-7a3d-4974-81b8-2f6fef280947';
        $locationId = 'fdf67648-c2dd-4622-9a2b-bca6706026f1';
        $destinationId = 'f292f48d-0904-4819-8d57-12e36f7a2fca';
        DB::table('predefined_locations')->insert([
            'id' => $locationId, 'name' => 'Kandy', 'code' => 'template-kandy', 'type' => 'tour',
            'country' => 'Sri Lanka', 'latitude' => 7.29, 'longitude' => 80.63, 'google_place_id' => 'template-google',
        ]);
        DB::table('tour_destinations')->insert([
            'id' => $destinationId, 'service_type_id' => $serviceId, 'predefined_location_id' => $locationId,
            'code' => 'template-kandy', 'name' => 'Kandy', 'is_published' => true,
        ]);

        $controller = new ServicePackageController(app(PricingContextPolicyService::class));
        $validate = new \ReflectionMethod($controller, 'assertTourItineraryTemplate');
        $activityId = 'f985d2b4-0f43-425e-b608-1cd123a887b2';
        DB::table('tour_activities')->insert([
            'id' => $activityId,
            'tour_destination_id' => $destinationId,
            'predefined_location_id' => $locationId,
            'code' => 'garden-gate',
            'name' => 'Garden gate visit',
            'is_published' => true,
        ]);
        $template = ['days' => [[
            'day_number' => 1,
            'timezone' => 'Asia/Colombo',
            'start_destination_id' => $destinationId,
            'end_destination_id' => $destinationId,
            'stops' => [['kind' => 'destination', 'catalogue_id' => $destinationId]],
        ]]];
        $validate->invoke($controller, ServiceType::findOrFail($serviceId), $template);

        $template['days'][0]['stops'] = [['kind' => 'activity', 'catalogue_id' => $activityId]];
        $validate->invoke($controller, ServiceType::findOrFail($serviceId), $template);

        $template['days'][0]['end_destination_id'] = 'e0b17b74-93e2-49cb-aa73-7c1d12bb4a35';
        try {
            $validate->invoke($controller, ServiceType::findOrFail($serviceId), $template);
            self::fail('A package template must not contain an unpublished destination.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertTrue(true);
        }
    }

    public function test_publishing_package_template_keeps_immutable_versions(): void
    {
        $packageId = (string) \Illuminate\Support\Str::uuid();
        DB::table('service_packages')->insert([
            'id' => $packageId,
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'name' => 'Versioned tour',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $package = ServicePackage::findOrFail($packageId);
        $publish = new \ReflectionMethod(ServicePackageController::class, 'publishTourTemplateVersion');
        $first = ['days' => [['day_number' => 1, 'label' => 'Old route']]];
        $second = ['days' => [['day_number' => 1, 'label' => 'Updated route']]];
        $publish->invoke(new ServicePackageController(app(PricingContextPolicyService::class)), $package, $first);
        $package->refresh();
        $publish->invoke(new ServicePackageController(app(PricingContextPolicyService::class)), $package, $second);

        self::assertSame(2, $package->fresh()->tour_itinerary_template_version);
        self::assertSame($first, $package->tourTemplateVersions()->where('version', 1)->firstOrFail()->template);
        self::assertSame($second, $package->tourTemplateVersions()->where('version', 2)->firstOrFail()->template);
    }

    public function test_google_mapped_destinations_and_activity_visits_publish_without_ticket_fields(): void
    {
        $controller = new TourCatalogueController;
        $destinationLocation = $controller->storeLocation(Request::create('/tours/locations', 'POST', [
            'name' => 'Kandy', 'code' => 'tour-kandy', 'google_place_id' => 'google-kandy',
            'address' => 'Kandy, Sri Lanka', 'city' => 'Kandy', 'country' => 'Sri Lanka',
            'latitude' => 7.2906, 'longitude' => 80.6337,
        ]))->getData(true)['data'];
        $activityLocation = $controller->storeLocation(Request::create('/tours/locations', 'POST', [
            'name' => 'Botanical Garden Entrance', 'code' => 'tour-garden-gate', 'google_place_id' => 'google-garden-gate',
            'address' => 'Peradeniya, Sri Lanka', 'city' => 'Peradeniya', 'country' => 'Sri Lanka',
            'latitude' => 7.2734, 'longitude' => 80.5966,
        ]))->getData(true)['data'];
        $destination = $controller->storeDestination(Request::create('/tours/destinations', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'predefined_location_id' => $destinationLocation['id'],
            'code' => 'kandy', 'name' => 'Kandy', 'timezone' => 'Asia/Colombo', 'is_published' => true,
        ]))->getData(true)['data'];
        $activity = $controller->storeActivity(Request::create('/tours/activities', 'POST', [
            'tour_destination_id' => $destination['id'],
            'predefined_location_id' => $activityLocation['id'],
            'code' => 'garden-visit', 'name' => 'Visit the garden',
            'description' => 'A planned stop; arrange entry separately.',
            'visit_duration_minutes' => 90, 'opens_at' => '08:00', 'closes_at' => '17:00',
            'is_published' => true,
        ]))->getData(true)['data'];

        $catalogue = $controller->published(Request::create('/public/tours/catalogue', 'GET', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
        ]))->getData(true);

        self::assertSame($destination['id'], $catalogue['data']['destinations'][0]['id']);
        self::assertSame(90, $catalogue['data']['destinations'][0]['activities'][0]['visit_duration_minutes']);
        self::assertSame((float) $activityLocation['latitude'], $catalogue['data']['destinations'][0]['activities'][0]['location']['latitude']);
        self::assertArrayNotHasKey('google_place_id', $catalogue['data']['destinations'][0]['activities'][0]['location']);
        self::assertStringContainsString('Tickets, entry fees and activity reservations are not included', $catalogue['activity_visit_note']);
        self::assertArrayNotHasKey('ticket_price', $activity);
    }

    public function test_ai_suggestion_routes_activities_to_their_access_points_across_days_and_uses_published_ids(): void
    {
        $controller = new TourCatalogueController;
        $location = $controller->storeLocation(Request::create('/tours/locations', 'POST', [
            'name' => 'Kandy', 'code' => 'ai-kandy', 'google_place_id' => 'google-ai-kandy',
            'country' => 'Sri Lanka', 'latitude' => 7.29, 'longitude' => 80.63,
        ]))->getData(true)['data'];
        $destination = $controller->storeDestination(Request::create('/tours/destinations', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'predefined_location_id' => $location['id'], 'code' => 'ai-kandy', 'name' => 'Kandy',
            'timezone' => 'Asia/Colombo', 'is_published' => true,
        ]))->getData(true)['data'];
        $activity = $controller->storeActivity(Request::create('/tours/activities', 'POST', [
            'tour_destination_id' => $destination['id'],
            'predefined_location_id' => $location['id'],
            'code' => 'ai-garden', 'name' => 'Garden visit', 'visit_duration_minutes' => 60,
            'is_published' => true,
        ]))->getData(true)['data'];
        $secondActivity = $controller->storeActivity(Request::create('/tours/activities', 'POST', [
            'tour_destination_id' => $destination['id'],
            'predefined_location_id' => $location['id'],
            'code' => 'ai-lake-visit', 'name' => 'Lake visit', 'visit_duration_minutes' => 45,
            'is_published' => true,
        ]))->getData(true)['data'];
        $published = \App\Models\Tours\TourDestination::query()->with([
            'activities' => fn($query) => $query->where('is_published', true),
        ])->where('is_published', true)->get();
        $sanitize = new \ReflectionMethod(TourCatalogueController::class, 'validatedAiSuggestionDays');
        $suggestion = $sanitize->invoke($controller, [[
            'day_number' => 1,
            'stops' => [['kind' => 'activity', 'catalogue_id' => $activity['id']]],
        ]], 1, $published);

        self::assertSame([
            ['kind' => 'activity', 'catalogue_id' => $activity['id']],
        ], $suggestion[0]['stops']);
        $spreadAcrossDays = $sanitize->invoke($controller, [
            ['day_number' => 1, 'stops' => [['kind' => 'activity', 'catalogue_id' => $activity['id']]]],
            ['day_number' => 2, 'stops' => [['kind' => 'activity', 'catalogue_id' => $secondActivity['id']]]],
        ], 2, $published);
        self::assertSame($secondActivity['id'], $spreadAcrossDays[1]['stops'][0]['catalogue_id']);
        try {
            $sanitize->invoke($controller, [['day_number' => 1, 'stops' => [['kind' => 'destination', 'catalogue_id' => (string) \Illuminate\Support\Str::uuid()]]]], 1, $published);
            self::fail('AI output must not introduce an unpublished or unknown location.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('published catalogue', $exception->getMessage());
        }
    }

    public function test_public_ai_suggestion_uses_only_the_published_tour_catalogue(): void
    {
        $controller = new TourCatalogueController;
        $location = $controller->storeLocation(Request::create('/tours/locations', 'POST', [
            'name' => 'Kandy', 'code' => 'ai-flow-kandy', 'google_place_id' => 'google-ai-flow-kandy',
            'country' => 'Sri Lanka', 'latitude' => 7.29, 'longitude' => 80.63,
        ]))->getData(true)['data'];
        $destination = $controller->storeDestination(Request::create('/tours/destinations', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'predefined_location_id' => $location['id'], 'code' => 'ai-flow-kandy', 'name' => 'Kandy',
            'timezone' => 'Asia/Colombo', 'is_published' => true,
        ]))->getData(true)['data'];
        $activity = $controller->storeActivity(Request::create('/tours/activities', 'POST', [
            'tour_destination_id' => $destination['id'], 'predefined_location_id' => $location['id'],
            'code' => 'ai-flow-garden', 'name' => 'Garden visit', 'visit_duration_minutes' => 60,
            'is_published' => true,
        ]))->getData(true)['data'];

        $settings = \Mockery::mock(WebsiteSettingsService::class);
        $settings->shouldReceive('get')->with('tour_ai_itinerary_enabled', false)->andReturn(true);
        app()->instance(WebsiteSettingsService::class, $settings);
        $ai = \Mockery::mock(AIContentService::class);
        $ai->shouldReceive('suggestTourItinerary')->once()->with(
            ['duration_days' => 1, 'start_date' => '2026-12-01', 'interests' => 'gardens'],
            \Mockery::on(fn(array $catalogue): bool => count($catalogue) === 1 && $catalogue[0]['id'] === $destination['id'] && !array_key_exists('latitude', $catalogue[0]))
        )->andReturn(['days' => [['day_number' => 1, 'stops' => [['kind' => 'activity', 'catalogue_id' => $activity['id']]]]]]);
        $request = Request::create('/api/public/tours/suggestions', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'duration_days' => 1, 'start_date' => '2026-12-01', 'interests' => 'gardens',
        ]);
        $result = $controller->suggestPublicItinerary($request, $ai)->getData(true);

        self::assertTrue($result['success']);
        self::assertSame([
            ['kind' => 'activity', 'catalogue_id' => $activity['id']],
        ], $result['data']['days'][0]['stops']);
        self::assertStringContainsString('do not confirm availability', $result['data']['notice']);
    }

    public function test_tour_destination_cannot_use_an_unmapped_existing_location(): void
    {
        $location = PredefinedLocation::create([
            'name' => 'Unmapped', 'code' => 'unmapped', 'type' => 'general',
            'latitude' => 7.1, 'longitude' => 80.1, 'country' => 'Sri Lanka',
        ]);

        $this->expectException(ValidationException::class);
        (new TourCatalogueController)->storeDestination(Request::create('/tours/destinations', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'predefined_location_id' => $location->id,
            'code' => 'unmapped', 'name' => 'Unmapped', 'timezone' => 'Asia/Colombo',
        ]));
    }

    public function test_route_suggestion_uses_published_access_coordinates_and_keeps_activity_visit(): void
    {
        $controller = new TourCatalogueController;
        $destinationLocation = $controller->storeLocation(Request::create('/tours/locations', 'POST', [
            'name' => 'Kandy', 'code' => 'route-kandy', 'google_place_id' => 'google-route-kandy',
            'country' => 'Sri Lanka', 'latitude' => 7.29, 'longitude' => 80.63,
        ]))->getData(true)['data'];
        $activityLocation = $controller->storeLocation(Request::create('/tours/locations', 'POST', [
            'name' => 'Garden gate', 'code' => 'route-gate', 'google_place_id' => 'google-route-gate',
            'country' => 'Sri Lanka', 'latitude' => 7.27, 'longitude' => 80.59,
        ]))->getData(true)['data'];
        $destination = $controller->storeDestination(Request::create('/tours/destinations', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'predefined_location_id' => $destinationLocation['id'],
            'code' => 'route-kandy', 'name' => 'Kandy', 'timezone' => 'Asia/Colombo', 'is_published' => true,
        ]))->getData(true)['data'];
        $activity = $controller->storeActivity(Request::create('/tours/activities', 'POST', [
            'tour_destination_id' => $destination['id'], 'predefined_location_id' => $activityLocation['id'],
            'code' => 'route-garden', 'name' => 'Garden visit', 'visit_duration_minutes' => 75, 'is_published' => true,
        ]))->getData(true)['data'];

        $classId = 'ad150b30-e73c-43c8-8b46-884c5297caf9';
        DB::table('service_vehicle_class_settings')->insert([
            'id' => '4b94ea37-3a86-4387-9002-c097032194ee',
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'vehicle_class_id' => $classId,
            'max_passengers' => 3,
            'max_luggage' => 2,
        ]);
        $planner = $this->createMock(TourRoutePlanner::class);
        $planner->expects(self::once())->method('suggest')->with(self::callback(function (array $stops) use ($destination, $activity): bool {
            self::assertSame([$destination['id'], $activity['id']], array_column($stops, 'catalogue_id'));
            self::assertSame([7.29, 7.27], array_column($stops, 'latitude'));
            self::assertSame([0, 75], array_column($stops, 'visit_minutes'));

            return true;
        }))->willReturn([
            'status' => 'ready',
            'original' => ['distance_km' => 22.0],
            'suggested' => ['distance_km' => 10.0],
        ]);
        $pricing = $this->createMock(BookingFlowService::class);
        $pricing->expects(self::exactly(2))->method('calculateDynamicPricing')
            ->with(self::callback(fn (array $params): bool => $params['vehicle_class_id'] === $classId
                && $params['service_type_id'] === 'c2c58e96-7a3d-4974-81b8-2f6fef280947'
                && $params['pricing_context'] === 'public'))
            ->willReturnOnConsecutiveCalls(
                ['total_amount' => 50000, 'calculation_metadata' => ['definition_used' => 'tours-definition']],
                ['total_amount' => 45000, 'calculation_metadata' => ['definition_used' => 'tours-definition']]
            );

        $currency = $this->createMock(CurrencyService::class);
        $currency->method('getBookingBaseCurrency')->willReturn('LKR');
        $currency->method('convertPricingStructure')->willReturnArgument(0);
        $response = $controller->suggestRoute(Request::create('/public/tours/route-suggestion', 'POST', [
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'vehicle_class_id' => $classId,
            'from_date' => '2026-12-01',
            'duration_days' => 2,
            'adult_count' => 2,
            'child_count' => 0,
            'luggage_count' => 2,
            'stops' => [
                ['visit_id' => 'day-1-destination', 'kind' => 'destination', 'catalogue_id' => $destination['id'], 'latitude' => 0, 'longitude' => 0],
                ['visit_id' => 'day-1-garden', 'kind' => 'activity', 'catalogue_id' => $activity['id'], 'latitude' => 0, 'longitude' => 0],
            ],
        ]), $planner, $pricing, $currency)->getData(true);

        self::assertSame('ready', $response['data']['status']);
        self::assertEquals(-5000, $response['data']['price_difference']);
        self::assertSame('priced', $response['data']['suggested']['quote']['status']);
    }

    public function test_shared_pricing_input_builder_forwards_the_purchased_class(): void
    {
        $settings = $this->createMock(WebsiteSettingsService::class);
        $settings->method('getBookingSettings')->willReturn([]);
        app()->instance(WebsiteSettingsService::class, $settings);
        $service = (new \ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BookingFlowService::class, 'prepareCalculationInputs');
        $method->setAccessible(true);

        $inputs = $method->invoke($service, ['vehicle_class_id' => 'ad150b30-e73c-43c8-8b46-884c5297caf9']);

        self::assertSame('ad150b30-e73c-43c8-8b46-884c5297caf9', $inputs['vehicle_class_id']);
        self::assertArrayNotHasKey('vehicle_group_id', $inputs);
    }

    public function test_catalogue_migration_refuses_to_drop_published_data(): void
    {
        DB::table('predefined_locations')->insert([
            'id' => 'b0b8d301-999c-4f2f-a31d-8ea4d40069ad', 'name' => 'Kandy', 'code' => 'migration-test',
            'type' => 'tour', 'country' => 'Sri Lanka', 'latitude' => 7.2906, 'longitude' => 80.6337,
            'google_place_id' => 'google-kandy', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tour_destinations')->insert([
            'id' => 'bacbb5fd-c13e-4bc3-9022-5e94fe6f324b',
            'service_type_id' => 'c2c58e96-7a3d-4974-81b8-2f6fef280947',
            'predefined_location_id' => 'b0b8d301-999c-4f2f-a31d-8ea4d40069ad',
            'code' => 'migration-test', 'name' => 'Kandy', 'timezone' => 'Asia/Colombo',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_02_000003_create_tour_catalogue.php');
    }
}
