<?php

use App\Support\ObservabilitySanitizer;

it('validates and records frontend observability events', function () {
    $this->postJson('/api/observability/frontend-log', [
        'level' => 'error',
        'message' => 'OBSERVABILITY_FRONTEND_TEST',
        'timestamp' => now()->toISOString(),
    ])->assertAccepted();
});

it('redacts credentials in frontend observability events', function () {
    expect(ObservabilitySanitizer::text('Bearer secret.token ?access_token=secret-value'))
        ->toBe('[REDACTED] ?access_token=[REDACTED]');
});
