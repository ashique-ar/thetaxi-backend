<?php

use App\Support\ObservabilitySanitizer;
use App\Logging\CreateLokiLogger;

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

it('routes backend and browser logs directly to Loki', function () {
    expect(config('logging.channels.loki_backend.driver'))->toBe('custom')
        ->and(config('logging.channels.loki_backend.labels.component'))->toBe('backend')
        ->and(config('logging.channels.loki_frontend.labels.component'))->toBe('frontend')
        ->and(config('logging.channels.loki_backend'))->toHaveKey('password')
        ->and(config('logging.channels.loki_frontend'))->toHaveKey('password')
        ->and(config('logging.channels.loki_backend'))->not->toHaveKey('password_file');

    $logger = (new CreateLokiLogger)(config('logging.channels.loki_backend'));
    expect($logger->getTimezone()->getName())->toBe(config('app.timezone'));
});

it('keeps debug tracing out of application logging', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            expect(file_get_contents($file->getPathname()))
                ->not->toMatch('/\\bLog::debug\\s*\\(/');
        }
    }
});
