<?php

namespace Tests\Unit;

use App\Http\Controllers\BookingController;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\DiscountService;
use App\Services\MailDispatchService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class BookingSearchPaginationContextTest extends TestCase
{
    public function test_load_more_uses_the_search_context_from_its_results_tab(): void
    {
        $activeSearchId = '4f6a11cc-d206-4d29-a797-7f11c36aad72';
        $otherSearchId = 'ff4b1642-c861-4f6d-8960-b79c3c8e5182';
        $session = app('session')->driver();
        $session->start();
        $session->put('session_id', '');
        $session->put('current_search_params', ['service_type_id' => 'other-tab-service']);
        $session->put('public_search_contexts', [
            $activeSearchId => [
                'search_params' => ['service_type_id' => 'airport_transfers', 'page' => 1],
                'search_timestamp' => now(),
                'pricing_context' => 'public',
            ],
            $otherSearchId => [
                'search_params' => ['service_type_id' => 'ride_now', 'page' => 1],
                'search_timestamp' => now(),
                'pricing_context' => 'public',
            ],
        ]);

        $bookingFlow = Mockery::mock(BookingFlowService::class);
        $bookingFlow->shouldReceive('getAvailableVehicleGroups')
            ->once()
            ->with(Mockery::on(static fn (array $params): bool =>
                ($params['service_type_id'] ?? null) === 'airport_transfers'
                && ($params['page'] ?? null) === 2
            ), true)
            ->andReturn(['data' => [], 'pagination' => ['last_page' => 2]]);

        $controller = new BookingSearchPaginationContextController(
            $bookingFlow,
            Mockery::mock(CurrencyService::class),
            Mockery::mock(DiscountService::class),
            Mockery::mock(MailDispatchService::class),
        );
        $request = Request::create('/search/vehicles/page', 'GET', [
            'page' => 2,
            'search_context_id' => $activeSearchId,
        ]);
        $request->setLaravelSession($session);

        $response = $controller->loadMoreSearchResults($request);

        self::assertSame(200, $response->getStatusCode());
    }
}

class BookingSearchPaginationContextController extends BookingController
{
    protected function transformResultsForPublicView(array $vehicleGroups, array $searchParams, $pricingContext): array
    {
        return [];
    }
}
