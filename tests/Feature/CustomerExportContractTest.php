<?php

use App\Http\Controllers\Api\CustomerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('exposes a real permission-protected customer CSV download', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($candidate) =>
        $candidate->uri() === 'api/customers/export'
        && in_array('GET', $candidate->methods(), true)
    );

    expect($route)->not->toBeNull()
        ->and($route->getActionName())->toBe(CustomerController::class.'@exportCustomers')
        ->and($route->gatherMiddleware())->toContain('permission:customers.export');

    $response = app(CustomerController::class)->exportCustomers(
        Request::create('/api/customers/export', 'GET')
    );

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toContain('attachment; filename=customers-');
});
