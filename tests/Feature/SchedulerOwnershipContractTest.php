<?php

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

it('registers every retained operational schedule through the Laravel 13 console route owner', function (): void {
    $events = collect(Schedule::events())
        ->filter(fn (Event|CallbackEvent $event): bool => $event instanceof Event)
        ->mapWithKeys(function (Event $event): array {
            $command = preg_replace('/^.*\bartisan["\']?\s+/', '', $event->command ?? '');

            return [$command => $event];
        });

    $expected = [
        'sitemap:generate-and-ping' => '0 0 * * *',
        'short-urls:cleanup' => '0 0 * * *',
        'bookings:generate-recurring' => '0 1 * * *',
        'maintenance:check-scheduled' => '0 6 * * *',
        'corporate-transport:generate-bookings' => '*/15 * * * *',
        'bookings:retry-final-pricing' => '*/15 * * * *',
    ];

    foreach ($expected as $command => $expression) {
        expect($events)->toHaveKey($command)
            ->and($events[$command]->expression)->toBe($expression);
    }

    expect($events['bookings:generate-recurring']->withoutOverlapping)->toBeTrue()
        ->and($events['maintenance:check-scheduled']->withoutOverlapping)->toBeTrue()
        ->and($events['corporate-transport:generate-bookings']->withoutOverlapping)->toBeTrue()
        ->and($events['bookings:retry-final-pricing']->withoutOverlapping)->toBeTrue()
        ->and($events->keys()->contains(fn (string $command): bool => str_starts_with($command, 'queue:work ')))->toBeFalse()
        ->and(base_path('app/Console/Kernel.php'))->not->toBeFile();
});
