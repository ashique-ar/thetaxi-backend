<?php

use App\Services\BookingFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates readable service types through the authorized booking scope', function () {
    [$admin] = hr_seed_admin_actor();
    $serviceTypeId = '7f36a634-5c25-4d7d-9b35-c63254452811';
    $bookingRows = ['bookings' => [[
        'booking_number' => 'BK-1001',
        'service_type' => ['id' => $serviceTypeId, 'name' => 'Airport Transfer'],
    ]]];
    $service = Mockery::mock(BookingFlowService::class);
    $service->shouldReceive('getFilteredBookings')->once()->with(Mockery::on(fn ($filters) =>
        $filters['search'] === 'Airport' && $filters['per_page'] === 50
    ))->andReturn($bookingRows);
    $service->shouldReceive('getFilteredBookings')->once()->with(Mockery::on(fn ($filters) =>
        $filters['service_type'] === $serviceTypeId && ! isset($filters['search'])
    ))->andReturn($bookingRows);
    $service->shouldReceive('getFilteredBookings')->once()->with(Mockery::on(fn ($filters) =>
        $filters['search'] === 'Airport' && $filters['operations_queue'] === 'active'
    ))->andReturn($bookingRows);
    $service->shouldReceive('getFilteredBookings')->once()->with(Mockery::on(fn ($filters) =>
        $filters['service_type'] === $serviceTypeId && $filters['operations_queue'] === 'active'
    ))->andReturn($bookingRows);
    app()->instance(BookingFlowService::class, $service);
    $url = '/api/booking-flow/return-inspection/options?record_type=service_type';

    actingAs($admin, 'api')->getJson($url.'&search=Airport&per_page=50')->assertOk()
        ->assertJsonPath('data.0.value', $serviceTypeId)
        ->assertJsonPath('data.0.label', 'Airport Transfer');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$serviceTypeId)
        ->assertOk()->assertJsonPath('data.0.value', $serviceTypeId);
    actingAs($admin, 'api')->getJson($url.'&search=Airport&per_page=50&operations_queue=active')
        ->assertOk()->assertJsonPath('data.0.label', 'Airport Transfer');
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$serviceTypeId.'&operations_queue=active')
        ->assertOk()->assertJsonPath('data.0.value', $serviceTypeId);
});
