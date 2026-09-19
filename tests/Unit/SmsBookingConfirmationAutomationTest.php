<?php

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Customer;
use App\Models\Booking\BookingDispatch;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use App\Models\Inquiry;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Models\User;
use App\Services\Sms\SmsAutomationService;
use App\Services\Sms\BookingCommunicationActivityService;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsSettingsService;
use App\Services\Sms\SmsSegmentCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

uses(Tests\TestCase::class);

it('previews the admin summary with example data without queueing an SMS', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'admin_booking_summary_template' => 'DEFAULT {booking_number}',
    ]);
    $sms->shouldNotReceive('queueSingleMessage');

    $preview = (new SmsAutomationService($settings, $sms))
        ->previewAdminBookingSummary(null, 'PREVIEW {booking_number} {customer_name} {total}');

    expect($preview['source'])->toBe('example')
        ->and($preview['booking_id'])->toBeNull()
        ->and($preview['message'])->toBe('PREVIEW BK-EXAMPLE-001 Example Customer 8,500.00');
});

it('previews any transactional template with the production segment and cost rules', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'cost_per_segment' => 2,
        'cost_currency' => 'LKR',
    ]);
    $sms->shouldNotReceive('queueSingleMessage');

    $preview = (new SmsAutomationService($settings, $sms))
        ->previewTransactionalTemplate('booking.confirmed', str_repeat('A', 161));

    expect($preview['encoding'])->toBe('gsm7')
        ->and($preview['characters'])->toBe(161)
        ->and($preview['segments'])->toBe(2)
        ->and($preview['estimated_cost'])->toBe(4.0)
        ->and($preview['cost_currency'])->toBe('LKR');
});

it('queues one customer confirmation and one consolidated summary per configured admin number', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);

    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'booking_confirmation_enabled' => true,
        'admin_booking_summary_enabled' => true,
        'admin_booking_summary_numbers' => ['94771234567', '94777654321'],
        'booking_confirmation_template' => 'Confirmed {booking_number} at {pickup_datetime}',
        'admin_booking_summary_template' => 'ADMIN {booking_number} {customer_name} {item_count} {total}',
    ]);

    $payloads = [];
    $sms->shouldReceive('queueSingleMessage')->times(3)->andReturnUsing(
        function (array $payload) use (&$payloads) {
            $payloads[] = $payload;
            return new \App\Models\Sms\SmsMessage();
        }
    );

    $user = new User();
    $user->setRawAttributes(['first_name' => 'Nadia', 'last_name' => 'Perera', 'phone' => '0770000000']);
    $customer = new Customer();
    $customer->setRelation('user', $user);

    $first = new BookingItem();
    $first->setRawAttributes([
        'from_date' => '2026-08-20 10:30:00',
        'pickup_location' => json_encode(['address' => 'Colombo']),
        'dropoff_location' => json_encode(['address' => 'Kandy']),
        'currency' => 'LKR',
        'total_price' => 5000,
    ]);
    $second = new BookingItem();
    $second->setRawAttributes(['total_price' => 2500]);

    $booking = new Booking();
    $booking->setRawAttributes([
        'booking_number' => 'BK-1001',
        'currency' => 'LKR',
        'total_estimated' => 7500,
        'confirmed_at' => Carbon::parse('2026-08-11 09:00:00'),
    ]);
    $booking->id = '11111111-1111-1111-1111-111111111111';
    $booking->setRelation('customer', $customer);
    $booking->setRelation('bookingItems', new Collection([$first, $second]));

    (new SmsAutomationService($settings, $sms))->queueBookingConfirmation($booking);

    expect(array_column($payloads, 'event_key'))->toBe([
        'booking.confirmed',
        'admin.booking_confirmed_summary',
        'admin.booking_confirmed_summary',
    ])->and(array_column($payloads, 'recipient'))->toBe([
        '0770000000',
        '94771234567',
        '94777654321',
    ])->and(array_unique(array_column($payloads, 'idempotency_key')))->toHaveCount(3)
        ->and($payloads[1]['message'])->toContain('ADMIN BK-1001 Nadia Perera 2 7,500.00')
        ->and($payloads[1]['source'])->toBe('automation')
        ->and($payloads[1]['booking_id'])->toBe($booking->id);
});

it('does not queue admin summaries when the policy is disabled', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);

    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'booking_confirmation_enabled' => false,
        'admin_booking_summary_enabled' => false,
        'admin_booking_summary_numbers' => ['94771234567'],
    ]);
    $sms->shouldNotReceive('queueSingleMessage');

    $booking = new Booking();
    $booking->setRawAttributes(['booking_number' => 'BK-1002']);
    $booking->id = '22222222-2222-2222-2222-222222222222';
    $booking->setRelation('customer', null);
    $booking->setRelation('bookingItems', new Collection());

    (new SmsAutomationService($settings, $sms))->queueBookingConfirmation($booking);
});

it('honours the internal confirmation choice without suppressing configured admin summaries', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'booking_confirmation_enabled' => true,
        'admin_booking_summary_enabled' => true,
        'admin_booking_summary_numbers' => ['94771234567'],
        'booking_confirmation_template' => 'CUSTOMER {booking_number}',
        'admin_booking_summary_template' => 'ADMIN {booking_number}',
    ]);

    $payload = null;
    $sms->shouldReceive('queueSingleMessage')->once()->andReturnUsing(
        function (array $message) use (&$payload) {
            $payload = $message;
            return new \App\Models\Sms\SmsMessage();
        }
    );

    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-choice', 'booking_number' => 'BK-CHOICE']);
    $booking->setRelation('customer', null);
    $booking->setRelation('bookingItems', new Collection());

    (new SmsAutomationService($settings, $sms))->queueBookingConfirmation($booking, false);

    expect($payload['event_key'])->toBe('admin.booking_confirmed_summary')
        ->and($payload['recipient'])->toBe('94771234567')
        ->and($payload['message'])->toBe('ADMIN BK-CHOICE');
});

it('keeps customer confirmation SMS off after an unticked booking is confirmed through another path', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'booking_confirmation_enabled' => true,
        'admin_booking_summary_enabled' => false,
        'booking_confirmation_template' => 'Confirmed {booking_number}',
    ]);
    $sms->shouldNotReceive('queueSingleMessage');

    $user = new User();
    $user->setRawAttributes(['phone' => '0770000000']);
    $customer = new Customer();
    $customer->setRelation('user', $user);
    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-unticked', 'booking_number' => 'BK-UNTICKED', 'notification_sms' => false]);
    $booking->setRelation('customer', $customer);
    $booking->setRelation('bookingItems', new Collection());

    (new SmsAutomationService($settings, $sms))->queueBookingConfirmation($booking);
});

it('renders booking schedule times from the dedicated time columns', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'cost_per_segment' => 0,
        'cost_currency' => 'LKR',
    ]);

    $item = new BookingItem();
    $item->setRawAttributes([
        'id' => 'item-time-source',
        'from_date' => '2026-08-20 00:00:00',
        'from_time' => '16:45:00',
        'to_date' => '2026-08-20 00:00:00',
        'to_time' => '18:05:00',
    ]);

    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-time-source', 'booking_number' => 'BK-TIME']);
    $booking->setRelation('customer', null);
    $booking->setRelation('bookingItems', new Collection([$item]));

    $preview = (new SmsAutomationService($settings, $sms))->previewTransactionalTemplate(
        'booking.confirmed',
        '{pickup_date}|{pickup_time}|{pickup_datetime}|{dropoff_time}|{dropoff_datetime}',
        $booking
    );

    expect($preview['message'])
        ->toBe('20/08/2026|04:45 PM|20/08/2026 04:45 PM|06:05 PM|20/08/2026 06:05 PM')
        ->not->toContain('12:00 AM')
        ->not->toContain('05:30 AM');
});

it('queues item-scoped dispatched and assignment-scoped arrived customer messages', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->twice()->andReturn([
        'enabled' => true,
        'driver_dispatched_enabled' => true,
        'driver_arrived_enabled' => true,
        'driver_dispatched_template' => 'ON WAY {pickup_datetime} {driver_name} {driver_mobile} {vehicle_number}',
        'driver_arrived_template' => 'ARRIVED {pickup_datetime} {driver_name} {vehicle_number}',
    ]);

    $payloads = [];
    $sms->shouldReceive('queueSingleMessage')->twice()->andReturnUsing(
        function (array $payload) use (&$payloads) {
            $payloads[] = $payload;
            return new \App\Models\Sms\SmsMessage();
        }
    );

    $customerUser = new User();
    $customerUser->setRawAttributes(['first_name' => 'Nadia', 'last_name' => 'Perera', 'phone' => '0770000000']);
    $customer = new Customer();
    $customer->setRelation('user', $customerUser);

    $driverUser = new User();
    $driverUser->setRawAttributes(['first_name' => 'Kamal', 'last_name' => 'Silva', 'phone' => '0771111111']);
    $driver = new Driver();
    $driver->setRawAttributes(['id' => 'driver-1']);
    $driver->setRelation('user', $driverUser);

    $make = new VehicleMake();
    $make->setRawAttributes(['name' => 'Toyota']);
    $model = new VehicleModel();
    $model->setRawAttributes(['name' => 'Axio']);
    $group = new \App\Models\Vehicle\VehicleGroup();
    $group->setRelation('make', $make);
    $group->setRelation('model', $model);
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['id' => 'vehicle-1', 'license_plate' => 'CAB-1234']);
    $vehicle->setRelation('group', $group);

    $item = new BookingItem();
    $item->setRawAttributes(['id' => 'item-1', 'from_date' => '2026-08-20 00:00:00', 'from_time' => '10:30:00']);
    $item->setRelation('vehicle', $vehicle);

    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-1', 'booking_number' => 'BK-2001']);
    $booking->setRelation('customer', $customer);
    $booking->setRelation('bookingItems', new Collection([$item]));

    $dispatch = new BookingDispatch();
    $dispatch->setRawAttributes(['id' => 'dispatch-1', 'booking_item_id' => 'item-1', 'dispatched_at' => Carbon::now()]);
    $dispatch->setRelation('driver', $driver);
    $dispatch->setRelation('vehicle', $vehicle);

    $assignment = new DriverAssignment();
    $assignment->setRawAttributes(['id' => 'assignment-1', 'booking_item_id' => 'item-1', 'pickup_arrived_at' => Carbon::now()]);
    $assignment->setRelation('driver', $driver);
    $assignment->setRelation('bookingItem', $item);

    $automation = new SmsAutomationService($settings, $sms);
    $automation->queueDriverDispatched($booking, $dispatch);
    $automation->queueDriverArrived($booking, $assignment);

    expect(array_column($payloads, 'event_key'))->toBe(['driver.dispatched', 'driver.arrived'])
        ->and($payloads[0]['booking_item_id'])->toBe('item-1')
        ->and($payloads[0]['recipient'])->toBe('0770000000')
        ->and($payloads[1]['recipient'])->toBe('0770000000')
        ->and($payloads[0]['driver_assignment_id'])->toBeNull()
        ->and($payloads[1]['driver_assignment_id'])->toBe('assignment-1')
        ->and($payloads[0]['message'])->toContain('ON WAY 20/08/2026 10:30 AM Kamal Silva 0771111111 CAB-1234')
        ->and($payloads[1]['message'])->toContain('ARRIVED 20/08/2026 10:30 AM Kamal Silva CAB-1234')
        ->and($payloads[0]['idempotency_key'])->not->toBe($payloads[1]['idempotency_key']);
});

it('does not call the legacy customer assignment SMS automation from assignment creation', function (): void {
    $source = file_get_contents(app_path('Services/AssignmentService.php'));

    expect($source)->not->toContain('queueDriverAssignment(')
        ->and($source)->toContain('SendCustomerDriverAssignedNotificationJob::dispatch');
});

it('routes direct internal confirmation SMS through the explicit request choice', function (): void {
    $source = file_get_contents(app_path('Services/BookingFlowService.php'));

    expect($source)->toContain("\$params['send_confirmation_sms'] ?? false")
        ->and($source)->toContain('smsAutomationService->queueBookingConfirmation(')
        ->and($source)->not->toContain("'template_key' => 'booking_confirmation'");
});

it('queues automatic website quotation and inquiry acknowledgements with stable identities', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->twice()->andReturn([
        'enabled' => true,
        'quotation_requested_enabled' => true,
        'inquiry_received_enabled' => true,
        'quotation_requested_template' => 'QUOTE {booking_number} {customer_name}',
        'inquiry_received_template' => 'INQUIRY {inquiry_number} {customer_name} {inquiry_type}',
    ]);

    $payloads = [];
    $sms->shouldReceive('queueSingleMessage')->twice()->andReturnUsing(
        function (array $payload) use (&$payloads) {
            $payloads[] = $payload;
            return new \App\Models\Sms\SmsMessage();
        }
    );

    $user = new User();
    $user->setRawAttributes(['first_name' => 'Nadia', 'last_name' => 'Perera', 'phone' => '0770000000']);
    $customer = new Customer();
    $customer->setRelation('user', $user);

    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'website-quote-1', 'booking_number' => 'QT000101']);
    $booking->setRelation('customer', $customer);
    $booking->setRelation('bookingItems', new Collection());

    $inquiry = new Inquiry();
    $inquiry->setRawAttributes([
        'id' => 'website-inquiry-1',
        'inquiry_number' => 'INQ000101',
        'name' => 'Nadia Perera',
        'phone' => '0770000000',
        'inquiry_type' => 'general',
    ]);

    $automation = new SmsAutomationService($settings, $sms);
    $automation->queueWebsiteQuotationRequested($booking);
    $automation->queueWebsiteInquiryReceived($inquiry);

    expect(array_column($payloads, 'event_key'))->toBe([
        'website.quotation_requested',
        'website.inquiry_received',
    ])->and($payloads[0]['message'])->toBe('QUOTE QT000101 Nadia Perera')
        ->and($payloads[1]['message'])->toBe('INQUIRY INQ000101 Nadia Perera general')
        ->and($payloads[0]['idempotency_key'])->not->toBe($payloads[1]['idempotency_key']);
});

it('keeps automatic public SMS hooks in website controllers only', function (): void {
    $checkout = file_get_contents(app_path('Http/Controllers/CheckoutController.php'));
    $inquiries = file_get_contents(app_path('Http/Controllers/InquiryController.php'));
    $apiSubmission = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));

    expect($checkout)->toContain('queueWebsiteQuotationRequested($booking)')
        ->and($checkout)->toContain('queueBookingConfirmation($booking, true)')
        ->and($inquiries)->toContain('queueWebsiteInquiryReceived($inquiry)')
        ->and($apiSubmission)->not->toContain('queueWebsiteQuotationRequested');
});

it('uses the canonical typed event contract and exposes a provider-safe dry run', function (): void {
    $automation = file_get_contents(app_path('Services/Sms/SmsAutomationService.php'));
    $service = file_get_contents(app_path('Services/Sms/SmsService.php'));
    $job = file_get_contents(app_path('Jobs/SendSmsMessageJob.php'));

    expect($automation)->toContain('TransactionalSmsEvent::BookingConfirmed->value')
        ->and($automation)->toContain('private function resolveBooking')
        ->and($service)->toContain("'status' => \$dryRun ? 'dry_run'")
        ->and($service)->toContain('if ($dryRun)')
        ->and($job)->toContain('public array $backoff = [30, 120, 300]')
        ->and($job)->toContain('public function failed(Throwable $exception)');
});

it('records skipped automation decisions for the communication timeline', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $activities = Mockery::mock(BookingCommunicationActivityService::class);

    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'booking_confirmation_enabled' => false,
        'admin_booking_summary_enabled' => false,
        'admin_booking_summary_numbers' => [],
    ]);
    $sms->shouldNotReceive('queueSingleMessage');
    $activities->shouldReceive('record')->once()->with(Mockery::on(
        fn(array $activity): bool => $activity['event_key'] === 'booking.confirmed'
            && $activity['result_status'] === 'disabled'
            && $activity['booking_id'] === 'booking-disabled'
    ))->andReturn(new \App\Models\Booking\BookingActivity());

    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-disabled', 'booking_number' => 'BK-DISABLED']);
    $booking->setRelation('customer', null);
    $booking->setRelation('bookingItems', new Collection());

    (new SmsAutomationService($settings, $sms, $activities))->queueBookingConfirmation($booking);
});

it('keeps non-queued provider execution outside the lifecycle transaction', function (): void {
    $source = file_get_contents(app_path('Services/Sms/SmsService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_11_000002_create_booking_activities_table.php'));
    $auditMigration = file_get_contents(database_path('migrations/2026_09_12_000004_add_audit_fields_to_booking_activities_table.php'));

    expect($source)->toContain('DB::afterCommit(function () use ($message)')
        ->and($migration)->toContain("Schema::create('booking_activities'")
        ->and($migration)->toContain("\$table->string('result_status')->index()")
        ->and($migration)->toContain("\$table->string('idempotency_key')->unique()")
        ->and($auditMigration)->toContain("\$table->uuid('created_user_id')->nullable()->index()")
        ->and($auditMigration)->toContain("\$table->uuid('updated_user_id')->nullable()->index()");
});

it('records trip start without SMS and queues optional aggregate completion once', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $activities = Mockery::mock(BookingCommunicationActivityService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'trip_completion_enabled' => true,
        'trip_completion_scope' => 'booking',
        'trip_completion_template' => 'COMPLETED {booking_number}',
    ]);

    $activities->shouldReceive('record')->once()->with(Mockery::on(
        fn(array $activity): bool => $activity['event_key'] === 'trip.started'
            && $activity['channel'] === 'timeline'
            && $activity['result_status'] === 'recorded'
    ))->andReturn(new \App\Models\Booking\BookingActivity());
    $activities->shouldReceive('recordMessage')->once()->andReturn(new \App\Models\Booking\BookingActivity());

    $message = new \App\Models\Sms\SmsMessage();
    $message->setRawAttributes(['status' => 'queued', 'event_key' => 'trip.completed']);
    $message->wasRecentlyCreated = true;
    $sms->shouldReceive('queueSingleMessage')->once()->with(Mockery::on(
        fn(array $payload): bool => $payload['event_key'] === 'trip.completed'
            && $payload['booking_item_id'] === null
            && $payload['driver_assignment_id'] === null
            && $payload['message'] === 'COMPLETED BK-COMPLETE'
    ))->andReturn($message);

    $user = new User();
    $user->setRawAttributes(['first_name' => 'Nadia', 'last_name' => 'Perera', 'phone' => '0770000000']);
    $customer = new Customer();
    $customer->setRelation('user', $user);
    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-complete', 'booking_number' => 'BK-COMPLETE', 'status' => 'completed']);
    $booking->setRelation('customer', $customer);
    $booking->setRelation('bookingItems', new Collection());
    $assignment = new DriverAssignment();
    $assignment->setRawAttributes(['id' => 'assignment-complete', 'booking_item_id' => 'item-complete']);

    $automation = new SmsAutomationService($settings, $sms, $activities);
    $automation->recordTripStarted($booking, $assignment);
    $automation->queueTripCompleted($booking, $assignment);
});

it('ships every documented transactional template in the settings migration', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_08_11_000003_seed_transactional_sms_templates.php'));

    foreach ([
        'sms_inquiry_received_template',
        'sms_quotation_requested_template',
        'sms_booking_confirmation_template',
        'sms_driver_assignment_fallback_template',
        'sms_driver_dispatched_template',
        'sms_driver_arrived_template',
        'sms_trip_completion_template',
        'sms_payment_confirmation_template',
        'sms_admin_booking_summary_template',
    ] as $templateKey) {
        expect($migration)->toContain($templateKey);
    }

    expect($migration)->toContain("'sms_dry_run' => 'false'")
        ->and($migration)->toContain('if (!$exists)');
});

it('keeps live SMS delivery enabled across fresh installs and later settings migrations', function (): void {
    $seedMigration = file_get_contents(database_path('migrations/2026_08_11_000003_seed_transactional_sms_templates.php'));
    $upsertMigration = file_get_contents(database_path('migrations/2026_08_11_000006_upsert_transactional_sms_templates.php'));
    $activationMigration = file_get_contents(database_path('migrations/2026_08_12_000001_disable_sms_dry_run.php'));

    expect($seedMigration)->toContain("'sms_dry_run' => 'false'")
        ->and($upsertMigration)->toContain("'sms_dry_run' => 'false'")
        ->and($upsertMigration)->not->toContain("\$query->update([")
        ->and($activationMigration)->toContain("->where('type', 'sms_dry_run')")
        ->and($activationMigration)->toContain("'value' => 'false'")
        ->and($activationMigration)->toContain('Do not silently disable live SMS delivery during a rollback.');
});

it('migrates shipped SMS templates to explicit booking time tokens without overwriting custom text', function (): void {
    $migration = file_get_contents(database_path('migrations/2026_08_13_120000_use_booking_time_columns_in_sms_templates.php'));

    expect($migration)
        ->toContain('{pickup_date}')
        ->toContain('{pickup_time}')
        ->toContain("->where('value', \$templates['old'])")
        ->toContain('Business-owned')
        ->toContain("whereNull('company_id')");
});

it('deduplicates payment SMS by provider payment reference', function (): void {
    $settings = Mockery::mock(SmsSettingsService::class);
    $sms = Mockery::mock(SmsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'enabled' => true,
        'payment_confirmation_enabled' => true,
        'payment_confirmation_template' => 'PAID {booking_number} {currency} {amount} {payment_reference}',
    ]);

    $payload = null;
    $sms->shouldReceive('queueSingleMessage')->once()->andReturnUsing(function (array $data) use (&$payload) {
        $payload = $data;
        $message = new \App\Models\Sms\SmsMessage();
        $message->setRawAttributes(['status' => 'queued']);
        $message->wasRecentlyCreated = true;
        return $message;
    });

    $user = new User();
    $user->setRawAttributes(['phone' => '0770000000']);
    $customer = new Customer();
    $customer->setRelation('user', $user);
    $booking = new Booking();
    $booking->setRawAttributes(['id' => 'booking-payment', 'booking_number' => 'BK-PAID']);
    $booking->setRelation('customer', $customer);
    $booking->setRelation('bookingItems', new Collection());

    (new SmsAutomationService($settings, $sms))->queuePaymentConfirmation($booking, 2500, 'LKR', 'PAY-1001');

    expect($payload['event_key'])->toBe('payment.received')
        ->and($payload['message'])->toBe('PAID BK-PAID LKR 2,500.00 PAY-1001')
        ->and($payload['idempotency_key'])->toBe(hash('sha256', implode('|', [
            'booking', 'booking-payment', 'PAY-1001', 'payment.received', '94770000000',
        ])));
});

it('provides authenticated acknowledgement and uniquely scheduled safe driver fallback', function (): void {
    $routes = file_get_contents(base_path('routes/api_driver.php'));
    $notificationService = file_get_contents(app_path('Services/Driver/NotificationTriggerService.php'));
    $job = file_get_contents(app_path('Jobs/SendDriverAssignmentFallbackSmsJob.php'));
    $payloadService = file_get_contents(app_path('Services/Driver/MobileAssignmentService.php'));

    expect($routes)->toContain("Route::post('{id}/acknowledge'")
        ->and($job)->toContain('ShouldBeUnique')
        ->and($job)->toContain("driver-notifications")
        ->and($notificationService)->toContain('lockForUpdate()')
        ->and($notificationService)->toContain('skipped_acknowledged')
        ->and($notificationService)->toContain('skipped_reassigned')
        ->and($notificationService)->toContain('skipped_expired')
        ->and($payloadService)->toContain("'notification_id'")
        ->and($payloadService)->toContain("'acknowledgement_required'")
        ->and($payloadService)->toContain("'acknowledged_at'")
        ->and($payloadService)->toContain("'accepted'");
});

it('calculates GSM and Unicode SMS segments at multipart boundaries', function (): void {
    $calculator = new SmsSegmentCalculator();

    expect($calculator->calculate(str_repeat('A', 160))['segments'])->toBe(1)
        ->and($calculator->calculate(str_repeat('A', 161))['segments'])->toBe(2)
        ->and($calculator->calculate(str_repeat('අ', 70))['segments'])->toBe(1)
        ->and($calculator->calculate(str_repeat('අ', 71))['segments'])->toBe(2)
        ->and($calculator->calculate('Hello')['encoding'])->toBe('gsm7')
        ->and($calculator->calculate('ආයුබෝවන්')['encoding'])->toBe('unicode');
});

it('matches delivery callbacks only by exact provider identity and redacts secrets', function (): void {
    $service = file_get_contents(app_path('Services/Sms/SmsService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sms/SmsManagementController.php'));

    expect($service)->toContain("where('provider_transaction_id', \$transactionId)")
        ->and($service)->toContain("where('provider_message_id', \$messageId)")
        ->and($service)->not->toContain("where('normalized_recipient', \$recipient)")
        ->and($service)->toContain('redactProviderPayload')
        ->and($service)->toContain("whereIn('status', ['queued', 'pending', 'failed'])")
        ->and($controller)->toContain('hash_equals')
        ->and($controller)->toContain('X-SMS-Webhook-Secret');
});
