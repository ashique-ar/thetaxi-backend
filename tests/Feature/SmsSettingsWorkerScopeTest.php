<?php

use App\Http\Controllers\Api\Sms\SmsManagementController;
use App\Models\Sms\SmsMessage;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsAutomationService;
use App\Services\Sms\SmsSettingsService;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;

it('saves the SMS API key globally for queue workers and in the active company scope', function () {
    $smsService = Mockery::mock(SmsService::class);
    $smsSettingsService = Mockery::mock(SmsSettingsService::class);
    $websiteSettingsService = Mockery::mock(WebsiteSettingsService::class)->shouldIgnoreMissing();
    $smsAutomationService = Mockery::mock(SmsAutomationService::class)->shouldIgnoreMissing();

    $websiteSettingsService->shouldReceive('resolveCurrentCompanyId')
        ->once()
        ->andReturn('company-1');
    $websiteSettingsService->shouldReceive('setGlobal')
        ->once()
        ->with('sms_esms_api_key', 'configured-api-key');
    $websiteSettingsService->shouldReceive('set')
        ->once()
        ->with('sms_esms_api_key', 'configured-api-key', 'company-1');
    $smsSettingsService->shouldReceive('getSettings')->once()->andReturn([]);

    $controller = new SmsManagementController(
        $smsService,
        $smsSettingsService,
        $websiteSettingsService,
        $smsAutomationService
    );

    $response = $controller->updateSettings(Request::create('/api/sms/settings', 'PUT', [
        'sms_enabled' => true,
        'sms_provider' => 'esms',
        'sms_allow_mask_override' => true,
        'sms_queue_enabled' => true,
        'sms_dry_run' => false,
        'sms_bulk_chunk_size' => 250,
        'sms_cost_per_segment' => 0,
        'sms_cost_currency' => 'LKR',
        'sms_booking_status_enabled' => false,
        'sms_esms_api_key' => 'configured-api-key',
    ]));

    expect($response->getStatusCode())->toBe(200);
});

it('encrypts admin summary numbers at rest and decrypts normalized values for automation', function () {
    $websiteSettingsService = Mockery::mock(WebsiteSettingsService::class);
    $service = new SmsSettingsService($websiteSettingsService);
    $protected = $service->protectAdminNumbers(['0771234567', '+94777654321']);

    $websiteSettingsService->shouldReceive('getSmsSettings')->once()->andReturn([
        'sms_provider' => 'esms',
        'sms_default_sender_mask' => null,
        'sms_webhook_secret' => null,
        'sms_esms_base_url' => null,
        'sms_esms_username' => null,
        'sms_esms_password' => null,
        'sms_esms_api_key' => null,
        'sms_esms_esmsqk' => null,
        'sms_esms_delivery_callback_url' => null,
        'sms_admin_booking_summary_numbers' => $protected,
    ]);

    expect($protected)->toStartWith('enc:v1:')
        ->and($protected)->not->toContain('0771234567')
        ->and($service->getSettings()['admin_booking_summary_numbers'])->toBe([
            '94771234567',
            '94777654321',
        ]);
});

it('normalizes zero one or two unique admin numbers and caps defensive legacy input', function () {
    $service = new SmsSettingsService(Mockery::mock(WebsiteSettingsService::class));
    $method = new ReflectionMethod($service, 'adminNumbers');

    expect($method->invoke($service, []))->toBe([])
        ->and($method->invoke($service, ['0771234567']))->toBe(['94771234567'])
        ->and($method->invoke($service, ['0771234567', '+94771234567']))->toBe(['94771234567'])
        ->and($method->invoke($service, ['0771234567', '0777654321', '0711111111']))->toBe([
            '94771234567',
            '94777654321',
        ]);
});

it('rate limits SMS surfaces separately and isolates campaigns from transactional workers', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
    $smsService = file_get_contents(app_path('Services/Sms/SmsService.php'));
    $campaignJob = file_get_contents(app_path('Jobs/LaunchSmsCampaignJob.php'));

    expect($provider)->toContain("RateLimiter::for('sms-send'")
        ->and($provider)->toContain("RateLimiter::for('sms-test'")
        ->and($provider)->toContain("RateLimiter::for('sms-campaign'")
        ->and($provider)->toContain("RateLimiter::for('sms-webhook'")
        ->and($routes)->toContain("middleware('throttle:sms-send')")
        ->and($routes)->toContain("middleware('throttle:sms-campaign')")
        ->and($routes)->toContain("middleware('throttle:sms-webhook')")
        ->and($campaignJob)->toContain("\$this->queue = 'sms-campaigns'")
        ->and($smsService)->toContain("->onQueue('sms-campaigns')")
        ->and($smsService)->toContain("return \$message->channel === 'campaign'");
});

it('allowlists SMS log fields and hides recipient and body content from read-only viewers', function () {
    $controller = new SmsManagementController(
        Mockery::mock(SmsService::class),
        Mockery::mock(SmsSettingsService::class),
        Mockery::mock(WebsiteSettingsService::class),
        Mockery::mock(SmsAutomationService::class)
    );
    $message = new SmsMessage();
    $message->setRawAttributes([
        'id' => 'message-1',
        'recipient' => '0771234567',
        'normalized_recipient' => '94771234567',
        'message' => 'Private booking message',
        'status' => 'sent',
        'provider_response' => json_encode(['secret' => 'must-not-leak']),
    ]);
    $method = new ReflectionMethod($controller, 'messagePayload');
    $viewer = $method->invoke($controller, $message, false);
    $manager = $method->invoke($controller, $message, true);

    expect($viewer['recipient'])->toBe('*******4567')
        ->and($viewer['normalized_recipient'])->toBeNull()
        ->and($viewer['message'])->toBe('[Message content restricted]')
        ->and($viewer)->not->toHaveKey('provider_response')
        ->and($manager['recipient'])->toBe('94771234567')
        ->and($manager['message'])->toBe('Private booking message');
});

it('keeps SMS retention disabled and read-only until explicitly authorized', function () {
    $command = file_get_contents(app_path('Console/Commands/ApplySmsRetention.php'));
    $schedule = file_get_contents(base_path('routes/console.php'));
    $config = require config_path('sms.php');

    expect($config['retention']['enabled'])->toBeFalse()
        ->and($command)->toContain('{--execute : Apply redaction; without this option the command is read-only}')
        ->and($command)->toContain("if (!\$this->option('execute'))")
        ->and($command)->toContain("'recipient_hash' => hash('sha256', \$recipient)")
        ->and($command)->toContain("'message' => '[redacted by retention policy]'")
        ->and($schedule)->toContain("if (config('sms.retention.enabled'))")
        ->and($schedule)->toContain("Schedule::command('sms:apply-retention --execute')");
});

it('separates campaign marketing consent from essential transactional automation', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sms/SmsManagementController.php'));
    $smsService = file_get_contents(app_path('Services/Sms/SmsService.php'));
    $automationService = file_get_contents(app_path('Services/Sms/SmsAutomationService.php'));

    expect($controller)->toContain("'in:manual,customers'")
        ->and($controller)->toContain("'required_if:audience_type,manual', 'accepted'")
        ->and($smsService)->toContain("->where('marketing_consent', true)")
        ->and($smsService)->toContain("'consent_revalidated_at' => now()->toIso8601String()")
        ->and($smsService)->toContain("array_intersect(\$recipients, \$currentlyConsented)")
        ->and($automationService)->not->toContain('marketing_consent');
});

it('exposes provider-free operational SMS health checks', function () {
    $service = file_get_contents(app_path('Services/Sms/SmsService.php'));
    $config = require config_path('sms.php');

    expect($config['health'])->toHaveKeys([
        'queue_age_minutes',
        'callback_age_minutes',
        'failure_window_minutes',
        'failure_count',
        'messages_per_booking',
        'low_balance',
    ])->and($service)->toContain("'health' => \$this->getOperationalHealth()")
        ->and($service)->toContain("'key' => 'queue_age'")
        ->and($service)->toContain("'key' => 'failure_spike'")
        ->and($service)->toContain("'key' => 'callback_stale'")
        ->and($service)->toContain("'key' => 'booking_volume'")
        ->and($service)->not->toContain('getBalance(true)');
});

it('provides a strictly read-only production SMS readiness audit', function () {
    $command = file_get_contents(app_path('Console/Commands/AuditSmsReadiness.php'));

    expect($command)->toContain("sms:audit-readiness")
        ->and($command)->toContain('{--require-ready : Return failure when the Gate 1 rollout guard has blockers}')
        ->and($command)->toContain("'read_only' => true")
        ->and($command)->toContain("'worker_started' => false")
        ->and($command)->toContain("'provider_contacted' => false")
        ->and($command)->toContain("Schema::hasTable('jobs')")
        ->and($command)->toContain("Schema::hasTable('failed_jobs')")
        ->and($command)->toContain("Schema::hasTable('sms_messages')")
        ->and($command)->toContain("'gate_1_ready' => \$blockers === []")
        ->and($command)->toContain("'enabled_event_switches' => \$enabledSwitches")
        ->and($command)->toContain("historical SMS queue job(s) require classification")
        ->and($command)->toContain("non-terminal SMS message record(s) require classification")
        ->and($command)->toContain("\$this->option('require-ready') && empty(\$rolloutGuard['gate_1_ready'])")
        ->and($command)->not->toContain('queue:work')
        ->and($command)->not->toContain('queue:retry')
        ->and($command)->not->toContain('SendSmsMessageJob::dispatch');
});

it('provides a read-only single-booking SMS acceptance verifier', function () {
    $command = file_get_contents(app_path('Console/Commands/VerifySmsBooking.php'));

    expect($command)->toContain('sms:verify-booking')
        ->and($command)->toContain('TransactionalSmsEvent::BookingConfirmed')
        ->and($command)->toContain('TransactionalSmsEvent::DriverDispatched')
        ->and($command)->toContain('TransactionalSmsEvent::DriverArrived')
        ->and($command)->toContain("'admin_lifecycle_copies'")
        ->and($command)->toContain("'provider_contacted' => false")
        ->and($command)->toContain("'records_changed' => false")
        ->and($command)->not->toContain('queueSingleMessage')
        ->and($command)->not->toContain('dispatch(');
});

it('ships a read-only production service-unit audit script', function () {
    $script = file_get_contents(base_path('scripts/sms-production-service-audit.sh'));

    expect($script)->toContain('systemctl show "$UNIT_NAME"')
        ->and($script)->toContain('systemctl cat "$UNIT_NAME"')
        ->and($script)->toContain('WorkingDirectory,ExecStart,MainPID')
        ->and($script)->toContain('stat --format=')
        ->and($script)->toContain('sms:audit-readiness --json')
        ->and($script)->not->toContain('systemctl start')
        ->and($script)->not->toContain('systemctl restart')
        ->and($script)->not->toContain('systemctl stop')
        ->and($script)->not->toContain('queue:retry')
        ->and($script)->not->toContain('queue:work');
});

it('ships an isolated disabled-by-default transactional SMS canary unit template', function () {
    $unit = file_get_contents(base_path('deploy/systemd/thetaxi-sms-canary.service.example'));
    $runbook = file_get_contents(base_path('docs/SMS_CANARY_RUNBOOK.md'));

    expect($unit)->toContain('__PHP_BINARY__')
        ->and($unit)->toContain('__DEPLOYMENT_DIRECTORY__')
        ->and($unit)->toContain('__PROCESS_USER__')
        ->and($unit)->toContain('--queue=sms')
        ->and($unit)->toContain('Restart=no')
        ->and($unit)->not->toContain('WantedBy=')
        ->and($unit)->not->toContain('sms-campaigns')
        ->and($unit)->not->toContain('--queue=default')
        ->and($runbook)->toContain('sms:audit-readiness --json')
        ->and($runbook)->toContain('sms:verify-booking')
        ->and($runbook)->toContain('Do not retry or release old jobs.');
});

it('provides a provider-free operating-cycle monitor with a failing health exit', function () {
    $command = file_get_contents(app_path('Console/Commands/MonitorSmsCycle.php'));

    expect($command)->toContain('sms:monitor-cycle')
        ->and($command)->toContain('getOperationalHealth()')
        ->and($command)->toContain('getTransactionalComplianceReport($days)')
        ->and($command)->toContain("'admin_summary_count_mismatch'")
        ->and($command)->toContain("'provider_contacted' => false")
        ->and($command)->toContain('return $healthy ? self::SUCCESS : self::FAILURE')
        ->and($command)->not->toContain('getBalance(')
        ->and($command)->not->toContain('queueSingleMessage')
        ->and($command)->not->toContain('dispatch(');
});
