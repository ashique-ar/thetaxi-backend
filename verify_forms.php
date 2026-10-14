<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\DynamicServiceConfigurationService;
use App\Services\WebsiteSettingsService;

$dyn = app(DynamicServiceConfigurationService::class);

echo "=== TESTING CASONS (casons.lk) ===" . PHP_EOL;
request()->headers->set('host', 'casons.lk');
app()->instance('request', request());

$casonsId = app(WebsiteSettingsService::class)->resolveCompanyId();
echo "Resolved Casons Company ID: {$casonsId}" . PHP_EOL;

$casonsCodes = ['self_drive', 'with_driver', 'airport_transfers', 'ride_now', 'wedding_hire', 'corporate'];
foreach ($casonsCodes as $code) {
    $cfg = $dyn->getServiceFormConfiguration($code);
    $fieldsCount = count($cfg['fields'] ?? []);
    echo "  - {$code}: fields={$fieldsCount}" . ($fieldsCount > 0 ? " [OK]" : " [EMPTY: {$cfg['error']}]") . PHP_EOL;
}

echo PHP_EOL . "=== TESTING THE TAXI (thetaxi.lk) ===" . PHP_EOL;
request()->headers->set('host', 'thetaxi.lk');
app()->instance('request', request());

$thetaxiId = app(WebsiteSettingsService::class)->resolveCompanyId();
echo "Resolved The Taxi Company ID: {$thetaxiId}" . PHP_EOL;

$thetaxiCodes = ['ride_now', 'airport_transfers', 'day_rental', 'corporate'];
foreach ($thetaxiCodes as $code) {
    $cfg = $dyn->getServiceFormConfiguration($code);
    $fieldsCount = count($cfg['fields'] ?? []);
    echo "  - {$code}: fields={$fieldsCount}" . ($fieldsCount > 0 ? " [OK]" : " [EMPTY: {$cfg['error']}]") . PHP_EOL;
}
