<?php

use App\Mail\GeneralMail;
use App\Http\Controllers\Api\LoyaltyController;
use App\Models\LoyaltyTier;
use Illuminate\Support\Str;

function productionRegressionPath(string $path): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

it('passes the general email body under a non-reserved Blade variable', function (): void {
    $content = (new GeneralMail([
        'subject' => 'Production regression',
        'message' => "First line\nSecond line",
    ]))->content();
    $view = file_get_contents(productionRegressionPath('resources/views/emails/general.blade.php'));

    expect($content->with)
        ->toHaveKey('emailBody', "First line\nSecond line")
        ->not->toHaveKey('message')
        ->and($view)
        ->toContain('$generalEmailBody = $emailBody')
        ->toContain('is_string($message)')
        ->toContain('e($generalEmailBody)');

    $previewController = file_get_contents(productionRegressionPath('app/Http/Controllers/Api/EmailTestController.php'));
    $generalPreview = Str::between($previewController, 'public function testGeneral()', 'public function testInquiryConfirmation()');

    expect($generalPreview)
        ->toContain("'emailBody' => \$message")
        ->not->toContain("'message' => \$message");
});

it('uses the Carbon period class provided by the Carbon package', function (): void {
    $source = file_get_contents(productionRegressionPath('app/Http/Controllers/Api/Vehicle/VehicleController.php'));

    expect($source)
        ->toContain('use Carbon\\CarbonPeriod;')
        ->not->toContain('use Illuminate\\Support\\CarbonPeriod;');
});

it('passes the explicit repeat-dispatch testing flag through the API controller', function (): void {
    $source = file_get_contents(productionRegressionPath('app/Http/Controllers/Api/Booking/BookingLifecycleController.php'));

    expect($source)
        ->toContain("'allow_repeat_dispatch_for_testing' => 'nullable|boolean'")
        ->toContain("'allow_repeat_dispatch_for_testing' => \$request->boolean('allow_repeat_dispatch_for_testing')");
});

it('loads vehicle make and model through the vehicle group for SMS automation', function (): void {
    $source = file_get_contents(productionRegressionPath('app/Services/Sms/SmsAutomationService.php'));

    expect($source)
        ->toContain('vehicle.group.make')
        ->toContain('vehicle.group.model')
        ->not->toContain("'vehicle.make'")
        ->not->toContain("'vehicle.model'");
});

it('provides the portal lifecycle trip end SMS bridge used after completion', function (): void {
    $automation = file_get_contents(productionRegressionPath('app/Services/Sms/SmsAutomationService.php'));
    $controller = file_get_contents(productionRegressionPath('app/Http/Controllers/Api/Booking/BookingLifecycleController.php'));

    expect($automation)
        ->toContain('public function queueTripEnd(Booking $booking, ?string $bookingItemId = null): void')
        ->toContain("->where('trip_phase', 'completed')")
        ->toContain('$this->queueTripCompleted($booking, $assignment)')
        ->and($controller)
        ->toContain("queueTripEnd(\$result, \$validated['booking_item_id'] ?? null)");
});

it('requires an explicit customer SMS decision for administrative force completion', function (): void {
    $controller = file_get_contents(productionRegressionPath('app/Http/Controllers/Api/Booking/BookingLifecycleController.php'));
    $dialog = file_get_contents(productionRegressionPath('../portal-thetaxi/src/app/modules/booking/components/ongoing-hire-management/dialogs/force-end-hire-dialog.component.ts'));

    expect($controller)
        ->toContain("'send_customer_sms' => 'required|boolean'")
        ->toContain('if ($aggregateCompleted && $sendCustomerSms)')
        ->and($dialog)
        ->toContain('Send trip-completion SMS to the customer?')
        ->toContain("send_customer_sms: value.send_customer_sms === 'yes'");
});

it('uses the UUID-native loyalty ledger instead of joining legacy integer reputation owners', function (): void {
    $source = file_get_contents(productionRegressionPath('app/Http/Controllers/Api/LoyaltyController.php'));

    expect($source)
        ->toContain('LoyaltyPointTransaction::query()')
        ->not->toContain("join('reputations'");
});

it('normalizes legacy string tier privileges before removing duplicates', function (): void {
    $tier = new LoyaltyTier();
    $tier->setRawAttributes([
        'privileges' => '"Airport lounge access"',
        'priority_booking' => false,
        'free_cancellation' => false,
        'priority_support' => true,
    ]);
    $method = new ReflectionMethod(LoyaltyController::class, 'tierBenefits');
    $controller = (new ReflectionClass(LoyaltyController::class))->newInstanceWithoutConstructor();

    expect($method->invoke($controller, $tier))
        ->toBe(['Airport lounge access', 'Priority support']);
});
