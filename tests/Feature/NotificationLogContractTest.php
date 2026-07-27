<?php

use App\Http\Controllers\Api\NotificationLogController;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('exposes only truthful notification log read contracts', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $index = $routes->first(fn ($route) => $route->uri() === 'api/notification-logs' && in_array('GET', $route->methods(), true));
    $show = $routes->first(fn ($route) => $route->uri() === 'api/notification-logs/{notification_log}' && in_array('GET', $route->methods(), true));

    expect($index)->not->toBeNull()
        ->and($show)->not->toBeNull()
        ->and($index->getActionName())->toBe(NotificationLogController::class . '@index')
        ->and($show->getActionName())->toBe(NotificationLogController::class . '@show')
        ->and($index->gatherMiddleware())->toContain('permission:notification-logs.view')
        ->and($show->gatherMiddleware())->toContain('permission:notification-logs.view')
        ->and($routes->contains(fn ($route) => $route->uri() === 'api/notification-logs/{notificationLog}/retry'))
        ->toBeFalse();
});

it('returns persisted recipient template delivery and content facts', function (): void {
    $recipient = User::create([
        'first_name' => 'Nadia',
        'last_name' => 'Perera',
        'email' => 'nadia@example.test',
    ]);
    $template = NotificationTemplate::create([
        'code' => 'booking-confirmed',
        'channel' => 'email',
        'subject' => 'Booking confirmed',
        'body' => 'Template body',
        'is_active' => true,
    ]);
    $log = NotificationLog::create([
        'user_id' => $recipient->id,
        'template_id' => $template->id,
        'content' => 'Your booking is confirmed.',
        'channel' => 'email',
        'sent_at' => now(),
        'status' => 'sent',
    ]);

    $response = app(NotificationLogController::class)->show($log);
    $payload = json_decode($response->getContent(), true);

    expect($payload['data']['log'])->toMatchArray([
        'id' => $log->id,
        'content' => 'Your booking is confirmed.',
        'channel' => 'email',
        'type' => 'email',
        'subject' => 'Booking confirmed',
        'template_code' => 'booking-confirmed',
        'recipient_name' => 'Nadia Perera',
        'recipient_contact' => 'nadia@example.test',
        'status' => 'sent',
        'delivery_status' => 'sent',
    ]);
});

