<?php

use App\Models\Booking\Booking;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateTransportProgram;
use App\Models\Corporate\CorporateTransportShift;
use App\Services\CorporateBookingService;
use App\Services\CorporateStaffTransportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    activity()->disableLogging();
    \Illuminate\Support\Facades\Notification::fake();
    foreach (['permissions', 'roles'] as $name) Schema::create($name, function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
    Schema::create('role_has_permissions', function (Blueprint $t) { $t->integer('role_id'); $t->integer('permission_id'); });
    (require database_path('migrations/2026_06_16_000002_create_corporate_transport_tables.php'))->up();
    foreach (['corporates', 'users', 'corporate_employees', 'corporate_employee_locations', 'vehicle_groups', 'service_types', 'bookings', 'booking_items', 'driver_assignment_stops'] as $name) {
        Schema::create($name, function (Blueprint $t) use ($name) {
            $t->uuid('id')->primary(); $t->timestamps(); $t->softDeletes();
            $t->uuid('created_user_id')->nullable(); $t->uuid('updated_user_id')->nullable();
            if (in_array($name, ['corporates', 'corporate_employees', 'corporate_employee_locations', 'vehicle_groups', 'service_types'])) $t->boolean('is_active')->default(true);
            if (in_array($name, ['corporates', 'vehicle_groups', 'service_types'])) $t->string('name')->nullable();
            if ($name === 'users') { $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->string('phone')->nullable(); }
            if ($name === 'corporate_employees') { $t->uuid('corporate_id'); $t->uuid('user_id'); $t->uuid('department_id')->nullable(); $t->uuid('division_id')->nullable(); $t->string('employee_code')->nullable(); }
            if ($name === 'corporate_employee_locations') { $t->uuid('corporate_employee_id'); $t->string('label')->nullable(); $t->string('address'); $t->decimal('latitude', 12, 8); $t->decimal('longitude', 12, 8); }
            if ($name === 'bookings') { $t->string('booking_number')->nullable(); $t->string('booking_source'); $t->json('workflow_data')->nullable(); $t->string('status')->default('confirmed'); }
            if ($name === 'booking_items') { $t->uuid('booking_id'); $t->string('status')->default('confirmed'); $t->decimal('total_price')->default(3500); $t->json('metadata')->nullable(); }
            if ($name === 'driver_assignment_stops') { $t->string('booking_stop_id'); $t->string('status'); $t->timestamp('completed_at')->nullable(); $t->string('skip_reason')->nullable(); }
        });
    }
    (require database_path('migrations/2026_09_14_000001_add_staff_transport_occurrence_key.php'))->up();
    $this->ids = array_combine(['company','program','route','shift','employee','user','location','group','service'], array_map(fn () => (string) Str::uuid(), range(1,9)));
    $id = $this->ids;
    DB::table('corporates')->insert(['id'=>$id['company'], 'name'=>'Company']);
    DB::table('users')->insert(['id'=>$id['user'], 'first_name'=>'Passenger']);
    DB::table('corporate_employees')->insert(['id'=>$id['employee'], 'corporate_id'=>$id['company'], 'user_id'=>$id['user']]);
    DB::table('corporate_employee_locations')->insert(['id'=>$id['location'], 'corporate_employee_id'=>$id['employee'], 'address'=>'Home', 'latitude'=>6.9, 'longitude'=>79.9]);
    DB::table('vehicle_groups')->insert(['id'=>$id['group'], 'name'=>'Van']);
    DB::table('service_types')->insert(['id'=>$id['service'], 'name'=>'Transfer']);
    DB::table('corporate_transport_programs')->insert(['id'=>$id['program'], 'corporate_id'=>$id['company'], 'name'=>'Commute']);
    DB::table('corporate_transport_shifts')->insert(['id'=>$id['shift'], 'program_id'=>$id['program'], 'name'=>'Morning', 'pickup_time'=>'08:00', 'dropoff_time'=>'09:00']);
    DB::table('corporate_transport_routes')->insert(['id'=>$id['route'], 'program_id'=>$id['program'], 'name'=>'Home to office', 'vehicle_group_id'=>$id['group'], 'service_type_id'=>$id['service'], 'capacity'=>10, 'destination_location'=>json_encode(['address'=>'Office','latitude'=>6.95,'longitude'=>79.95])]);
    DB::table('corporate_transport_route_members')->insert(['id'=>(string) Str::uuid(), 'route_id'=>$id['route'], 'corporate_employee_id'=>$id['employee'], 'pickup_location_id'=>$id['location']]);
    $this->service = new CorporateStaffTransportService;
    $this->travelTo(now()->setDate(2026,9,14)->setTime(22,0));
});

afterEach(function () { $this->travelBack(); DB::disconnect('sqlite'); });

it('honors opt in dates closures inactive staff and preserves employee choices on reruns', function () {
    DB::table('corporate_transport_programs')->update(['default_opt_mode'=>'opt_in', 'start_date'=>'2026-09-15', 'end_date'=>'2026-09-17', 'settings'=>json_encode(['excluded_dates'=>['2026-09-16']])]);
    expect($this->service->buildCalendar($this->ids['company'], '2026-09-14', 5)['created'])->toBe(2);
    expect(DB::table('corporate_transport_participations')->pluck('status')->unique()->all())->toBe(['opted_out']);
    DB::table('corporate_transport_participations')->where('service_date','2026-09-15')->update(['status'=>'on_leave']);
    $this->service->buildCalendar($this->ids['company'], '2026-09-14', 5);
    expect(DB::table('corporate_transport_participations')->where('service_date','2026-09-15')->value('status'))->toBe('on_leave');
});

it('generates a priced corporate trip once and preserves completed trips and prices on reruns', function () {
    $mock = Mockery::mock(CorporateBookingService::class);
    $mock->shouldReceive('createStaffTransportBooking')->once()->andReturnUsing(function ($company, $payload) {
        expect($payload['payment_collection_method'])->toBe('monthly_invoice');
        expect($payload['booking_items'][0]['metadata']['staff_transport_stops'])->toHaveCount(2);
        $bookingId=(string) Str::uuid();
        DB::table('bookings')->insert(['id'=>$bookingId,'booking_source'=>'corporate']);
        DB::table('booking_items')->insert(['id'=>(string) Str::uuid(),'booking_id'=>$bookingId,'total_price'=>3500]);
        return Booking::findOrFail($bookingId);
    });
    app()->instance(CorporateBookingService::class, $mock);
    expect($this->service->generateForDate('2026-09-15', $this->ids['company'], false, true)['created'])->toBe(1);
    DB::table('booking_items')->update(['status'=>'completed']);
    expect($this->service->generateForDate('2026-09-15', $this->ids['company'], false, true)['skipped'])->toBe(1);
    expect(DB::table('bookings')->count())->toBe(1);
    expect(DB::table('booking_items')->value('status'))->toBe('completed');
    expect((float) DB::table('booking_items')->value('total_price'))->toBe(3500.0);
    expect(DB::table('corporate_transport_participations')->value('booking_stop_id'))->toStartWith('booking-item:');
});

it('rolls back preview rosters and reports invalid locations without making bookings', function () {
    DB::table('corporate_employee_locations')->update(['is_active'=>false]);
    $result = $this->service->generateForDate('2026-09-15', $this->ids['company'], true, true);
    expect($result['errors'])->toBe(1);
    expect(DB::table('corporate_transport_participations')->count())->toBe(0);
    expect(DB::table('bookings')->count())->toBe(0);
});

it('rejects a saved location belonging to another employee', function () {
    DB::table('corporate_employee_locations')->update(['corporate_employee_id'=>(string) Str::uuid()]);
    expect(fn () => $this->service->saveMember($this->ids['company'], $this->ids['program'], $this->ids['route'], ['corporate_employee_id'=>$this->ids['employee'],'pickup_location_id'=>$this->ids['location']]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('converts program local times to UTC and advances overnight arrival', function () {
    $program = new CorporateTransportProgram(['timezone'=>'Asia/Colombo']);
    $shift = new CorporateTransportShift(['pickup_time'=>'23:30','dropoff_time'=>'01:00']);
    [$from,$to] = $this->service->journeyTimes($program,$shift,'2026-09-15');
    expect($from->toDateTimeString())->toBe('2026-09-15 18:00:00');
    expect($to->toDateTimeString())->toBe('2026-09-15 19:30:00');
});

it('returns employee attendance without leaking raw booking data', function () {
    $this->service->buildRoster($this->ids['company'], '2026-09-15');
    $row=DB::table('corporate_transport_participations')->first();
    DB::table('corporate_transport_participations')->where('id',$row->id)->update(['booking_stop_id'=>'own-stop']);
    DB::table('driver_assignment_stops')->insert(['id'=>(string) Str::uuid(),'booking_stop_id'=>'own-stop','status'=>'picked_up']);
    $calendar=$this->service->employeeCalendar(CorporateEmployee::findOrFail($this->ids['employee']), ['date_from'=>'2026-09-15','date_to'=>'2026-09-15'])->items();
    expect($calendar[0]['attendance_status'])->toBe('picked_up');
    expect($calendar[0])->not->toHaveKeys(['booking','metadata','pricing_snapshot']);
});

it('queues late employee changes without silently changing the manifest', function () {
    $this->service->buildRoster($this->ids['company'], '2026-09-15');
    $row = DB::table('corporate_transport_participations')->first();
    $employee = CorporateEmployee::findOrFail($this->ids['employee']);
    $updated = $this->service->setEmployeeParticipationStatus($employee, $row->id, 'on_leave', 'Medical appointment');
    expect($updated->status)->toBe('included');
    expect($updated->metadata['change_request']['status'])->toBe('on_leave');
    $updated = $this->service->setParticipationStatus($this->ids['company'], $row->id, 'on_leave', 'Approved by coordinator', true);
    expect($updated->status)->toBe('on_leave');
    expect($updated->metadata)->not->toHaveKey('change_request');
});

it('reconciles removed memberships without losing previous participation choices', function () {
    $this->service->buildRoster($this->ids['company'], '2026-09-15');
    DB::table('corporate_transport_route_members')->update(['is_active'=>false]);
    $this->service->buildRoster($this->ids['company'], '2026-09-15');
    expect(DB::table('corporate_transport_participations')->value('status'))->toBe('unavailable');
    DB::table('corporate_transport_route_members')->update(['is_active'=>true]);
    $this->service->buildRoster($this->ids['company'], '2026-09-15');
    expect(DB::table('corporate_transport_participations')->value('status'))->toBe('included');
});

it('builds exactly the manifest stops for the driver with matching passenger identifiers', function () {
    $item = new \App\Models\Booking\BookingItem;
    $item->id = (string) Str::uuid();
    $item->metadata = ['staff_transport'=>['direction'=>'pickup'], 'staff_transport_stops'=>[
        ['type'=>'pickup','stop_id'=>'staff-transport:person1','address'=>'Home','latitude'=>6.9,'longitude'=>79.9],
        ['type'=>'dropoff','stop_id'=>'staff-office:office','address'=>'Office','latitude'=>6.95,'longitude'=>79.95],
    ]];
    $reflection = new ReflectionClass(\App\Services\Driver\TripTrackingService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $stops = $reflection->getMethod('buildRouteStopsFromBookingItem')->invoke($service, $item);
    expect($stops)->toHaveCount(2);
    expect($stops[0]['booking_stop_id'])->toBe('booking-item:'.$item->id.':staff-transport:person1');
    expect($stops[1]['stop_type'])->toBe('dropoff');
});
