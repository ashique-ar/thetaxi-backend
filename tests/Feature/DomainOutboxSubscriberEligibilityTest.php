<?php

use App\Jobs\Foundation\RelayDomainOutboxEvent;
use App\Services\Foundation\DomainOutboxService;
use App\Services\Sales\SalesCommissionNotificationSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps unbound outbox events unpublished until their subscriber is registered', function () {
    $outbox = new DomainOutboxService();
    $unboundEventId = $outbox->record(
        'sales', 'commission_payout', (string) Str::uuid(),
        'sales.commission.accounting_ready', 2, 1, ['currency' => 'LKR'], now(),
    );
    $boundEventId = $outbox->record(
        'sales', 'commission_decision', (string) Str::uuid(),
        'sales.commission.decided', 1, 1, ['decision_id' => (string) Str::uuid()], now(),
    );

    expect($outbox->countDuePublications())->toBe(1)
        ->and($outbox->claimDuePublications()->pluck('id')->all())->toBe([$boundEventId])
        ->and(DB::table('domain_outbox_events')->where('id', $unboundEventId)->value('published_at'))->toBeNull()
        ->and(DB::table('domain_outbox_events')->where('id', $boundEventId)->value('published_at'))->not->toBeNull();

    $subscribers = config('foundation.outbox_subscribers');
    $subscribers['sales']['sales.commission.accounting_ready'] = SalesCommissionNotificationSubscriber::class;
    config(['foundation.outbox_subscribers' => $subscribers]);

    expect($outbox->countDuePublications())->toBe(1)
        ->and($outbox->claimDuePublications()->pluck('id')->all())->toBe([$unboundEventId])
        ->and(DB::table('domain_outbox_events')->where('id', $unboundEventId)->value('published_at'))->not->toBeNull();

    config(['foundation.outbox_subscribers' => []]);
    (new RelayDomainOutboxEvent($unboundEventId))->handle();

    expect(DB::table('domain_outbox_events')->where('id', $unboundEventId)->value('published_at'))->toBeNull();
});
