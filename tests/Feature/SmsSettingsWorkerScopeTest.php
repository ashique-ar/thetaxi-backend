<?php

use App\Http\Controllers\Api\Sms\SmsManagementController;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsSettingsService;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;

it('saves the SMS API key globally for queue workers and in the active company scope', function () {
    $smsService = Mockery::mock(SmsService::class);
    $smsSettingsService = Mockery::mock(SmsSettingsService::class);
    $websiteSettingsService = Mockery::mock(WebsiteSettingsService::class)->shouldIgnoreMissing();

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
        $websiteSettingsService
    );

    $response = $controller->updateSettings(Request::create('/api/sms/settings', 'PUT', [
        'sms_enabled' => true,
        'sms_provider' => 'esms',
        'sms_allow_mask_override' => true,
        'sms_queue_enabled' => true,
        'sms_bulk_chunk_size' => 250,
        'sms_booking_status_enabled' => false,
        'sms_esms_api_key' => 'configured-api-key',
    ]));

    expect($response->getStatusCode())->toBe(200);
});
