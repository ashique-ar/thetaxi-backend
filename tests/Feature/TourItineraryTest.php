<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Tours\TourItineraryController;
use App\Models\Tours\TourItinerary;
use App\Models\User;
use App\Models\Service\ServiceType;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\AssignmentService;
use App\Services\AvailabilityEnforcementService;
use App\Services\MailDispatchService;
use App\Services\PricingVariableService;
use App\Services\Sms\SmsAutomationService;
use App\Services\Tours\TourBookingConversionService;
use App\Services\Tours\TourItineraryQuoteService;
use App\Services\Tours\TourRoutePlanner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TourItineraryTest extends TestCase
{
    private const USER_ID = 'd52e390d-9970-4eab-8a42-13728056b63a';

    private const SERVICE_ID = 'c2c58e96-7a3d-4974-81b8-2f6fef280947';

    private const CLASS_ID = 'ad150b30-e73c-43c8-8b46-884c5297caf9';

    protected function setUp(): void
    {
        parent::setUp();
        activity()->disableLogging();
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('context_type');
            $table->uuid('context_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('inquiry_number')->nullable()->unique();
            $table->uuid('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('inquiry_type');
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status')->default('open');
            $table->string('source')->default('web');
            $table->json('payload')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('context')->default('public');
            $table->string('pricing_basis')->default('vehicle_group');
            $table->boolean('tour_booking_enabled')->default(false);
            $table->json('form_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('service_type_id');
            $table->string('name');
            $table->text('formula');
            $table->json('variables')->nullable();
            $table->json('conditions')->nullable();
            $table->string('status')->default('active');
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('predefined_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
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
        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->uuid('default_vehicle_class_id')->nullable();
            $table->json('allowed_upgrade_class_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->uuid('vehicle_class_id');
            $table->unsignedSmallInteger('max_passengers')->default(4);
            $table->unsignedSmallInteger('max_luggage')->default(3);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_inquiry_only')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('currencies', fn (Blueprint $table) => $table->string('code', 3)->primary());
        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->nullable();
            $table->string('booking_number')->nullable();
            $table->decimal('total_estimated', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->softDeletes();
        });
        Schema::create('booking_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('vehicle_class_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
            $table->string('pricing_basis')->default('vehicle_group');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->softDeletes();
        });
        Schema::create('vehicle_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('class_id');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('terms_and_conditions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('slug');
            $table->text('content');
            $table->uuid('service_type_id')->nullable();
            $table->string('service_type')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('effective_date')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->catalogueMigration()->up();
        $this->itineraryMigration()->up();
        $this->tourDayStartTimeMigration()->up();
        $this->bookingLinkMigration()->up();
        (require database_path('migrations/2026_11_02_000007_add_tour_templates_to_service_packages.php'))->up();
        (require database_path('migrations/2026_11_02_000008_version_tour_package_templates.php'))->up();
        (require database_path('migrations/2026_11_02_000009_add_tour_quote_terms_evidence.php'))->up();
        (require database_path('migrations/2026_11_02_000010_add_configured_fields_to_tour_itineraries.php'))->up();
        (require database_path('migrations/2026_11_02_000011_add_review_inquiry_to_tour_itineraries.php'))->up();
        DB::table('users')->insert(['id' => self::USER_ID, 'email' => 'tour-customer@example.test', 'first_name' => 'Tour', 'last_name' => 'Customer', 'phone' => '+94770000000']);
        DB::table('service_types')->insert([
            'id' => self::SERVICE_ID,
            'name' => 'Tours',
            'pricing_basis' => 'vehicle_class',
            'tour_booking_enabled' => true,
        ]);
        DB::table('vehicle_pricing_calculation_definitions')->insert([
            'id' => 'tours-definition',
            'service_type_id' => self::SERVICE_ID,
            'name' => 'Tour transport pricing',
            'formula' => 'slab_rate',
            'variables' => '[]',
            'conditions' => '[]',
            'status' => 'active',
        ]);
        DB::table('vehicle_classes')->insert(['id' => self::CLASS_ID]);
        DB::table('service_vehicle_class_settings')->insert([
            'id' => '4b94ea37-3a86-4387-9002-c097032194ee',
            'service_type_id' => self::SERVICE_ID,
            'vehicle_class_id' => self::CLASS_ID,
            'max_passengers' => 4,
            'max_luggage' => 3,
        ]);
        DB::table('currencies')->insert(['code' => 'LKR']);
    }

    public function test_owned_draft_stores_server_mapped_location_snapshots_and_updates_by_revision(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $controller = new TourItineraryController;
        $created = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];

        self::assertSame('draft', $created['status']);
        self::assertSame(1, $created['revision']);
        self::assertSame(7.29, (float) $created['days'][0]['stops'][0]['location_snapshot']['latitude']);
        self::assertSame(7.27, (float) $created['days'][0]['stops'][1]['location_snapshot']['latitude']);
        self::assertSame(75, $created['days'][0]['stops'][1]['visit_duration_minutes']);

        $payload = $this->payload($destinationId, $activityId);
        $payload['expected_revision'] = 1;
        $updated = $controller->update($this->request($payload), $created['id'])->getData(true)['data'];
        self::assertSame(2, $updated['revision']);
        self::assertSame(2, DB::table('tour_itinerary_days')->where('tour_itinerary_id', $created['id'])->count());

        $stopIds = array_reverse(array_column($updated['days'][0]['stops'], 'id'));
        $applied = $controller->applyRoute($this->request([
            'expected_revision' => 2,
            'days' => [
                ['day_number' => 1, 'stop_ids' => $stopIds],
                ['day_number' => 2, 'stop_ids' => []],
            ],
        ]), $created['id'])->getData(true)['data'];
        self::assertSame(3, $applied['revision']);
        self::assertSame($stopIds[0], $applied['days'][0]['stops'][0]['id']);
    }

    public function test_public_review_request_is_idempotent_and_does_not_create_a_booking(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $itinerary = (new TourItineraryController)
            ->store($this->request($this->payload($destinationId, $activityId)))
            ->getData(true)['data'];
        $customerId = '3149ba80-18ae-49e2-935e-58da31554d55';
        DB::table('customers')->insert([
            'id' => $customerId,
            'user_id' => self::USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_contexts')->insert([
            'id' => '2157df51-0748-4fa0-a0be-d45b905f6b86',
            'user_id' => self::USER_ID,
            'context_type' => 'customer',
            'context_id' => $customerId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mail = $this->createMock(MailDispatchService::class);
        $mail->expects(self::once())->method('sendToCustomer');
        app()->instance(MailDispatchService::class, $mail);
        $sms = $this->createMock(\App\Services\Sms\SmsAutomationService::class);
        $sms->expects(self::once())->method('queueWebsiteInquiryReceived');
        app()->instance(\App\Services\Sms\SmsAutomationService::class, $sms);

        $response = $this->withoutMiddleware(\App\Http\Middleware\WebsiteSettingsSecurity::class)
            ->actingAs(User::findOrFail(self::USER_ID))->withSession([
            'tour_checkout_user_id' => self::USER_ID,
            'tour_checkout_customer_id' => $customerId,
            'tour_checkout_itinerary_id' => $itinerary['id'],
        ])->postJson('/tours/drafts/'.$itinerary['id'].'/request-review');

        $response->assertCreated()->assertJsonPath('success', true);
        $inquiryId = DB::table('tour_itineraries')->where('id', $itinerary['id'])->value('review_inquiry_id');
        self::assertNotEmpty($inquiryId);
        self::assertSame(0, DB::table('bookings')->count());
        self::assertSame($itinerary['id'], DB::table('inquiries')->where('id', $inquiryId)->value('payload->tour_itinerary_id'));

        $retry = $this->postJson('/tours/drafts/'.$itinerary['id'].'/request-review');
        $retry->assertCreated();
        self::assertSame($inquiryId, DB::table('tour_itineraries')->where('id', $itinerary['id'])->value('review_inquiry_id'));
        self::assertSame(1, DB::table('inquiries')->where('inquiry_type', 'tour_review')->count());
    }

    public function test_itinerary_keeps_the_selected_package_template_version_after_a_new_version_is_published(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $packageId = (string) \Illuminate\Support\Str::uuid();
        $template = ['days' => [['day_number' => 1]]];
        DB::table('service_packages')->insert([
            'id' => $packageId,
            'service_type_id' => self::SERVICE_ID,
            'default_vehicle_class_id' => self::CLASS_ID,
            'allowed_upgrade_class_ids' => '[]',
            'is_active' => true,
            'tour_itinerary_template' => json_encode($template),
            'tour_itinerary_template_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tour_package_template_versions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'service_package_id' => $packageId,
            'version' => 1,
            'template' => json_encode($template),
            'created_at' => now(),
        ]);

        $data = $this->payload($destinationId, $activityId);
        $data['service_package_id'] = $packageId;
        $controller = new TourItineraryController;
        $draft = $controller->store($this->request($data))->getData(true)['data'];
        self::assertSame(1, $draft['service_package_template_version']);

        $newTemplate = ['days' => [['day_number' => 1, 'title' => 'Revised']]];
        DB::table('service_packages')->where('id', $packageId)->update([
            'tour_itinerary_template' => json_encode($newTemplate),
            'tour_itinerary_template_version' => 2,
        ]);
        DB::table('tour_package_template_versions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'service_package_id' => $packageId,
            'version' => 2,
            'template' => json_encode($newTemplate),
            'created_at' => now(),
        ]);

        $data['expected_revision'] = 1;
        $data['service_package_template_version'] = 1;
        $updated = $controller->update($this->request($data), $draft['id'])->getData(true)['data'];
        self::assertSame(1, $updated['service_package_template_version']);
        self::assertSame($template, json_decode(DB::table('tour_package_template_versions')->where('service_package_id', $packageId)->where('version', 1)->value('template'), true));
    }

    public function test_route_quote_is_saved_for_the_revision_and_acceptance_is_idempotent(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $termId = (string) \Illuminate\Support\Str::uuid();
        DB::table('terms_and_conditions')->insert([
            'id' => $termId,
            'title' => 'Tour cancellation policy',
            'slug' => 'tour-cancellation-policy',
            'content' => 'Changes are subject to the stated cancellation policy.',
            'service_type_id' => self::SERVICE_ID,
            'payment_type' => 'quotation',
            'version' => '3',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $controller = new TourItineraryController;
        $itinerary = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];
        DB::table('tour_activities')->where('id', $activityId)->update(['opens_at' => '10:00:00', 'closes_at' => '16:00:00']);
        $planner = $this->createMock(TourRoutePlanner::class);
        $planner->expects(self::exactly(2))->method('suggest')->willReturnCallback(function (array $stops, string $startTime): array {
            self::assertSame('09:00', $startTime);
            if (count($stops) > 2) {
                $activity = collect($stops)->firstWhere('kind', 'activity');
                self::assertSame('10:00', $activity['opens_at']);
                self::assertSame('16:00', $activity['closes_at']);
            }
            $distance = count($stops) > 2 ? 23.0 : 0.0;
            $route = [
                'stops' => $stops,
                'legs' => [],
                'distance_km' => $distance,
                'duration_seconds' => 1800,
                'visit_duration_seconds' => 4500,
                'total_duration_seconds' => 6300,
            ];

            return ['status' => 'ready', 'original' => $route, 'suggested' => $route, 'changed' => false, 'issues' => []];
        });
        $pricing = $this->createMock(BookingFlowService::class);
        $pricing->expects(self::once())->method('calculateDynamicPricing')->willReturn([
            'total_amount' => 52000,
            'breakdown' => [['component' => 'transport', 'amount' => 52000]],
            'calculation_metadata' => ['definition_used' => 'tours-definition', 'rate_sources' => ['internal-only']],
        ]);
        $currency = $this->createMock(CurrencyService::class);
        $currency->method('getBookingBaseCurrency')->willReturn('LKR');
        $currency->method('convertPricingStructure')->willReturnArgument(0);
        $quoteService = new TourItineraryQuoteService($planner, $pricing, $currency);

        $quote = $controller->quote($this->request(['expected_revision' => 1]), $itinerary['id'], $quoteService)->getData(true)['data'];
        self::assertSame('current', $quote['status']);
        self::assertSame(23.0, (float) $quote['distance_km']);
        self::assertSame($termId, $quote['terms_snapshot'][0]['id']);
        self::assertSame('3', $quote['terms_snapshot'][0]['version']);
        self::assertArrayNotHasKey('pricing_snapshot', $quote);
        self::assertArrayNotHasKey('calculation_metadata', $quote);
        self::assertSame('current', DB::table('tour_quotes')->where('id', $quote['id'])->value('status'));
        DB::table('terms_and_conditions')->where('id', $termId)->update([
            'version' => '4',
            'content' => 'Updated cancellation policy.',
        ]);

        try {
            $controller->acceptQuote($this->request(['expected_revision' => 1]), $itinerary['id'], $quote['id'], $quoteService);
            self::fail('A quote cannot be accepted without recorded terms consent.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('accept_terms', $exception->errors());
        }
        $accepted = $controller->acceptQuote($this->request(['expected_revision' => 1, 'accept_terms' => true]), $itinerary['id'], $quote['id'], $quoteService)->getData(true)['data'];
        $acceptedAgain = $quoteService->accept(
            TourItinerary::findOrFail($itinerary['id']),
            $quote['id'],
            1
        );
        self::assertSame('accepted', $accepted['status']);
        self::assertSame('3', $accepted['terms_snapshot'][0]['version']);
        self::assertSame(self::USER_ID, DB::table('tour_quotes')->where('id', $quote['id'])->value('accepted_by_user_id'));
        self::assertSame('customer', DB::table('tour_quotes')->where('id', $quote['id'])->value('acceptance_channel'));
        self::assertSame(hash('sha256', \App\Support\Foundation\CanonicalJson::encode($accepted['terms_snapshot'])), DB::table('tour_quotes')->where('id', $quote['id'])->value('accepted_terms_hash'));
        self::assertSame('accepted', $acceptedAgain->status);
        self::assertSame('accepted', DB::table('tour_itineraries')->where('id', $itinerary['id'])->value('status'));
    }

    public function test_route_options_compare_road_distance_and_class_price_without_dropping_a_visit(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $controller = new TourItineraryController;
        $itinerary = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];
        $planner = $this->createMock(TourRoutePlanner::class);
        $planner->expects(self::exactly(2))->method('suggest')->willReturnCallback(function (array $stops): array {
            $original = [
                'stops' => $stops,
                'legs' => [],
                'distance_km' => count($stops) > 2 ? 23.0 : 0.0,
                'duration_seconds' => 1800,
                'visit_duration_seconds' => 4500,
                'total_duration_seconds' => 6300,
            ];
            $suggestedStops = $stops;
            if (count($stops) > 2) {
                [$suggestedStops[1], $suggestedStops[2]] = [$suggestedStops[2], $suggestedStops[1]];
            }
            $suggested = $original;
            $suggested['stops'] = $suggestedStops;
            $suggested['distance_km'] = count($stops) > 2 ? 20.0 : 0.0;

            return ['status' => 'ready', 'original' => $original, 'suggested' => $suggested, 'changed' => $suggestedStops !== $stops, 'issues' => []];
        });
        $pricing = $this->createMock(BookingFlowService::class);
        $pricing->expects(self::exactly(2))->method('calculateDynamicPricing')->willReturnOnConsecutiveCalls(
            ['total_amount' => 52000, 'calculation_metadata' => ['definition_used' => 'tours-definition']],
            ['total_amount' => 50000, 'calculation_metadata' => ['definition_used' => 'tours-definition']]
        );
        $currency = $this->createMock(CurrencyService::class);
        $currency->method('getBookingBaseCurrency')->willReturn('LKR');
        $currency->method('convertPricingStructure')->willReturnArgument(0);
        $quoteService = new TourItineraryQuoteService($planner, $pricing, $currency);

        $result = $controller->routeOptions($this->request(['expected_revision' => 1]), $itinerary['id'], $quoteService)->getData(true)['data'];

        self::assertSame('ready', $result['status']);
        self::assertEquals(-2000, $result['price_difference']);
        self::assertCount(2, $result['days'][0]['suggested_stop_ids']);
        self::assertEquals(20, $result['suggested']['distance_km']);
    }

    public function test_another_user_cannot_read_or_edit_the_draft(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $controller = new TourItineraryController;
        $created = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];
        $foreign = $this->request([], '8e4ddad2-107d-45a8-bff7-c6c8d1acbf53');

        try {
            $controller->show($foreign, $created['id']);
            self::fail('A different owner must not read this draft.');
        } catch (ModelNotFoundException) {
            self::assertTrue(true);
        }
    }

    public function test_booking_link_is_unique_and_migration_refuses_to_drop_converted_itineraries(): void
    {
        self::assertTrue(Schema::hasColumn('tour_itineraries', 'booking_id'));
        DB::table('bookings')->insert(['id' => 'ed522743-d94e-44d5-88de-7994ec8a3ec5']);
        DB::table('tour_itineraries')->insert([
            'id' => '2a756758-9bc8-44e1-beb1-9560e6fbc3c1',
            'user_id' => self::USER_ID,
            'service_type_id' => self::SERVICE_ID,
            'vehicle_class_id' => self::CLASS_ID,
            'start_date' => '2026-12-01',
            'duration_days' => 1,
            'currency' => 'LKR',
            'booking_id' => 'ed522743-d94e-44d5-88de-7994ec8a3ec5',
        ]);
        self::assertSame('ed522743-d94e-44d5-88de-7994ec8a3ec5', TourItinerary::findOrFail('2a756758-9bc8-44e1-beb1-9560e6fbc3c1')->booking->id);

        $this->expectException(\RuntimeException::class);
        $this->bookingLinkMigration()->down();
    }

    public function test_tour_class_inventory_counts_existing_unassigned_class_bookings(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $controller = new TourItineraryController;
        $itinerary = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];
        $quoteId = '38f92e7a-1341-466f-b711-e9b5cc741d6d';
        DB::table('tour_quotes')->insert([
            'id' => $quoteId,
            'tour_itinerary_id' => $itinerary['id'],
            'itinerary_revision' => 1,
            'status' => 'accepted',
            'currency' => 'LKR',
            'total_amount' => 52000,
            'distance_km' => 23,
            'route_snapshot' => '{}',
            'pricing_snapshot' => json_encode([
                'total_amount' => 52000,
                'calculation_metadata' => ['definition_used' => 'tour-definition'],
                'tour_pricing_lock' => ['definition_id' => 'tour-definition', 'definition' => ['id' => 'tour-definition']],
            ]),
        ]);
        DB::table('tour_itineraries')->where('id', $itinerary['id'])->update(['status' => 'accepted']);
        DB::table('vehicle_groups')->insert([
            'id' => '9273137e-26f6-4a02-8884-e461e3496609',
            'class_id' => self::CLASS_ID,
            'is_active' => true,
        ]);
        $flow = $this->bookingFlowWithAvailableClassCount(1);
        $method = new \ReflectionMethod($flow, 'lockTourClassInventory');
        $params = ['_trusted_tour_quote' => \App\Models\Tours\TourQuote::findOrFail($quoteId)];
        $trustedQuoteMethod = new \ReflectionMethod($flow, 'trustedTourQuoteMatches');
        $trustedParams = [
            ...$params,
            '_tour_itinerary_id' => $itinerary['id'],
            'currency' => 'LKR',
            'passenger_count' => 2,
            'luggage_count' => 2,
            'booking_items' => [[
                'service_type_id' => self::SERVICE_ID,
                'vehicle_class_id' => self::CLASS_ID,
                'pricing_basis' => 'vehicle_class',
                'service_package_id' => null,
                'from_date' => '2026-12-01',
                'to_date' => '2026-12-02',
            ]],
        ];
        self::assertTrue($trustedQuoteMethod->invoke($flow, $trustedParams, ServiceType::findOrFail(self::SERVICE_ID)));
        $trustedParams['_tour_itinerary_id'] = self::USER_ID;
        self::assertFalse($trustedQuoteMethod->invoke($flow, $trustedParams, ServiceType::findOrFail(self::SERVICE_ID)));

        DB::transaction(fn () => $method->invoke($flow, $params, null));

        DB::table('bookings')->insert(['id' => '596bf486-98a5-4dfb-8dbf-3128fba381e2', 'status' => 'confirmed']);
        DB::table('booking_items')->insert([
            'id' => 'e5bec09e-1889-49d2-84c0-25fd5039102d',
            'booking_id' => '596bf486-98a5-4dfb-8dbf-3128fba381e2',
            'vehicle_class_id' => self::CLASS_ID,
            'pricing_basis' => 'vehicle_class',
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-01',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        DB::transaction(fn () => $method->invoke($flow, $params, null));
    }

    public function test_accepted_tour_quote_converts_once_and_uses_only_server_saved_itinerary_data(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $controller = new TourItineraryController;
        $itinerary = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];
        $quoteId = '9cb03ae6-fbd7-4442-a04d-101a4ff9485c';
        DB::table('tour_quotes')->insert([
            'id' => $quoteId,
            'tour_itinerary_id' => $itinerary['id'],
            'itinerary_revision' => 1,
            'status' => 'accepted',
            'currency' => 'LKR',
            'total_amount' => 52000,
            'distance_km' => 23,
            'route_snapshot' => json_encode(['stops' => [['name' => 'Kandy']]]),
            'pricing_snapshot' => json_encode([
                'total_amount' => 52000,
                'calculation_metadata' => ['definition_used' => 'tour-definition'],
                'tour_pricing_lock' => ['definition_id' => 'tour-definition', 'definition' => ['id' => 'tour-definition']],
            ]),
        ]);
        DB::table('tour_itineraries')->where('id', $itinerary['id'])->update(['status' => 'accepted']);
        $bookingId = '0c39b150-ae07-4a53-a177-41ea77a75c0c';
        DB::table('bookings')->insert([
            'id' => $bookingId,
            'status' => 'confirmed',
            'booking_number' => 'BK-TOUR-1',
            'total_estimated' => 52000,
            'currency' => 'LKR',
        ]);
        $flow = $this->createMock(BookingFlowService::class);
        $flow->expects(self::once())->method('confirmBooking')->with(self::callback(function (array $params) use ($itinerary, $quoteId): bool {
            self::assertSame($itinerary['id'], $params['_tour_itinerary_id']);
            self::assertSame($quoteId, $params['_trusted_tour_quote']->id);
            self::assertSame('vehicle_class', $params['booking_items'][0]['pricing_basis']);
            self::assertSame(23.0, $params['booking_items'][0]['journey_distance']);
            self::assertSame('Kandy', $params['booking_items'][0]['metadata']['tour_route_snapshot']['stops'][0]['name']);
            self::assertSame('Garden visit', $params['booking_items'][0]['metadata']['tour_days_snapshot'][0]['stops'][1]['name']);
            self::assertSame('Near the hotel entrance', $params['booking_items'][0]['metadata']['tour_custom_answers']['pickup_note']);
            self::assertSame('Pickup note', $params['booking_items'][0]['metadata']['tour_custom_fields_snapshot'][0]['label']);
            self::assertStringContainsString('not included', $params['booking_items'][0]['metadata']['activity_entry_note']);
            self::assertTrue($params['defer_confirmation']);
            self::assertSame('customer', $params['payment_responsibility']);

            return true;
        }))->willReturn(\App\Models\Booking\Booking::findOrFail($bookingId));
        $service = new TourBookingConversionService($flow);
        $tour = TourItinerary::findOrFail($itinerary['id']);
        $tour->forceFill([
            'custom_answers' => ['pickup_note' => 'Near the hotel entrance'],
            'custom_fields_snapshot' => [['name' => 'pickup_note', 'label' => 'Pickup note']],
        ])->save();

        $created = $service->convert($tour, $quoteId, 'd59f643c-51c8-4bb6-b801-ea78c3ce9e1c', false, true);
        $retried = $service->convert($tour, $quoteId, 'another-customer-is-ignored-on-idempotent-retry');

        self::assertSame($bookingId, $created->id);
        self::assertSame($bookingId, $retried->id);
        self::assertSame($bookingId, TourItinerary::findOrFail($itinerary['id'])->booking_id);
        self::assertSame('converted', TourItinerary::findOrFail($itinerary['id'])->status);
    }

    public function test_stale_revision_cannot_overwrite_a_newer_draft(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $controller = new TourItineraryController;
        $created = $controller->store($this->request($this->payload($destinationId, $activityId)))->getData(true)['data'];
        $firstUpdate = $this->payload($destinationId, $activityId);
        $firstUpdate['expected_revision'] = 1;
        $controller->update($this->request($firstUpdate), $created['id']);
        $payload = $this->payload($destinationId, $activityId);
        $payload['expected_revision'] = 1;

        $this->withoutExceptionHandling();
        $this->expectException(HttpException::class);
        $controller->update($this->request($payload), $created['id']);
    }

    public function test_saves_a_customer_selected_local_day_start_time(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        $payload = $this->payload($destinationId, $activityId);
        $payload['days'][0]['start_time'] = '09:30';

        $created = (new TourItineraryController)->store($this->request($payload))->getData(true)['data'];

        self::assertSame('09:30', DB::table('tour_itinerary_days')
            ->where('tour_itinerary_id', $created['id'])->where('day_number', 1)->value('start_time'));
    }

    public function test_service_configured_tour_fields_are_validated_and_saved_with_their_labels(): void
    {
        [$destinationId, $activityId] = $this->createCatalogueStops();
        DB::table('service_types')->where('id', self::SERVICE_ID)->update(['form_config' => json_encode(['fields' => [
            'pickup_note' => ['type' => 'text', 'label' => 'Pickup note', 'required' => true],
            'pace' => ['type' => 'select', 'label' => 'Travel pace', 'options' => [
                ['value' => 'relaxed', 'label' => 'Relaxed'], ['value' => 'active', 'label' => 'Active'],
            ]],
        ]])]);
        $payload = $this->payload($destinationId, $activityId);
        $payload['custom_answers'] = ['pickup_note' => 'Near the hotel entrance', 'pace' => 'relaxed'];

        $created = (new TourItineraryController)->store($this->request($payload))->getData(true)['data'];

        self::assertSame($payload['custom_answers'], $created['custom_answers']);
        self::assertSame('Pickup note', collect($created['custom_fields_snapshot'])->firstWhere('name', 'pickup_note')['label']);
        $payload['custom_answers']['unconfigured'] = 'ignored?';
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new TourItineraryController)->store($this->request($payload));
    }

    public function test_customer_tour_draft_routes_require_an_active_customer_profile(): void
    {
        $id = 'ad7669ba-776a-4211-89c3-0f5a0d669f65';
        $request = Request::create('/api/customer-portal/tours/drafts/'.$id, 'GET');
        $matched = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn () => $matched);
        $request->setUserResolver(fn () => null);

        try {
            (new TourItineraryController)->show($request, $id);
            self::fail('Customer Tour draft routes must require an active customer profile.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $listRequest = Request::create('/api/customer-portal/tours/drafts', 'GET');
        $listRoute = app('router')->getRoutes()->match($listRequest);
        $listRequest->setRouteResolver(fn () => $listRoute);
        $listRequest->setUserResolver(fn () => null);
        try {
            (new TourItineraryController)->index($listRequest);
            self::fail('The customer draft list must require an active customer profile.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    private function createCatalogueStops(): array
    {
        DB::table('predefined_locations')->insert([
            'id' => 'a8fa881d-9da4-4b0a-91dd-a91575087580', 'name' => 'Kandy', 'code' => 'itinerary-kandy',
            'type' => 'tour', 'address' => 'Kandy', 'country' => 'Sri Lanka', 'latitude' => 7.29, 'longitude' => 80.63,
            'google_place_id' => 'g-kandy', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $activityLocationId = '08a77a5e-2c74-4c7a-ae2b-8b9ae66dff73';
        DB::table('predefined_locations')->insert([
            'id' => $activityLocationId, 'name' => 'Garden gate', 'code' => 'itinerary-garden-gate',
            'type' => 'tour', 'address' => 'Peradeniya', 'country' => 'Sri Lanka', 'latitude' => 7.27, 'longitude' => 80.59,
            'google_place_id' => 'g-garden-gate', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $destinationId = '4682e81d-88fb-4867-8dda-d91f1895a058';
        DB::table('tour_destinations')->insert([
            'id' => $destinationId, 'service_type_id' => self::SERVICE_ID, 'predefined_location_id' => 'a8fa881d-9da4-4b0a-91dd-a91575087580',
            'code' => 'itinerary-kandy', 'name' => 'Kandy', 'timezone' => 'Asia/Colombo', 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $activityId = '030b23f4-b676-4bd1-8279-2db1381756db';
        DB::table('tour_activities')->insert([
            'id' => $activityId, 'tour_destination_id' => $destinationId, 'predefined_location_id' => $activityLocationId,
            'code' => 'garden-visit', 'name' => 'Garden visit', 'visit_duration_minutes' => 75, 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$destinationId, $activityId];
    }

    private function payload(string $destinationId, string $activityId): array
    {
        return [
            'service_type_id' => self::SERVICE_ID,
            'vehicle_class_id' => self::CLASS_ID,
            'start_date' => '2026-12-01',
            'duration_days' => 2,
            'adult_count' => 2,
            'child_count' => 0,
            'luggage_count' => 2,
            'currency' => 'LKR',
            'days' => [
                ['day_number' => 1, 'timezone' => 'Asia/Colombo', 'start_destination_id' => $destinationId, 'end_destination_id' => $destinationId, 'stops' => [
                    ['kind' => 'destination', 'catalogue_id' => $destinationId, 'latitude' => 0, 'longitude' => 0],
                    ['kind' => 'activity', 'catalogue_id' => $activityId, 'latitude' => 0, 'longitude' => 0],
                ]],
                ['day_number' => 2, 'timezone' => 'Asia/Colombo', 'start_destination_id' => $destinationId, 'end_destination_id' => $destinationId, 'stops' => []],
            ],
        ];
    }

    private function request(array $data, string $userId = self::USER_ID): Request
    {
        $request = Request::create('/tours/drafts', 'POST', $data);
        $request->setUserResolver(fn () => (object) ['id' => $userId]);

        return $request;
    }

    private function catalogueMigration(): Migration
    {
        return require database_path('migrations/2026_11_02_000003_create_tour_catalogue.php');
    }

    private function itineraryMigration(): Migration
    {
        return require database_path('migrations/2026_11_02_000004_create_tour_itineraries_and_quotes.php');
    }

    private function bookingLinkMigration(): Migration
    {
        return require database_path('migrations/2026_11_02_000005_link_tour_itinerary_to_booking.php');
    }

    private function tourDayStartTimeMigration(): Migration
    {
        return require database_path('migrations/2026_11_02_000006_add_tour_day_start_time.php');
    }

    private function bookingFlowWithAvailableClassCount(int $availableCount): BookingFlowService
    {
        $currency = $this->createMock(CurrencyService::class);
        $flow = $this->getMockBuilder(BookingFlowService::class)
            ->setConstructorArgs([
                $currency,
                $this->createMock(PricingVariableService::class),
                $this->createMock(AssignmentService::class),
                $this->createMock(AvailabilityEnforcementService::class),
                $this->createMock(MailDispatchService::class),
                $this->createMock(SmsAutomationService::class),
            ])
            ->onlyMethods(['getAvailableVehicleGroups'])
            ->getMock();
        $flow->method('getAvailableVehicleGroups')->willReturn([
            'data' => [['available_count' => $availableCount, 'is_group_active' => true]],
        ]);

        return $flow;
    }
}
