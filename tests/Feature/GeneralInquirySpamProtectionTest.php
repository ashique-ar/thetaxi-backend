<?php

use App\Http\Controllers\InquiryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

function generalInquirySpamCheck(array $input): bool
{
    $controller = (new ReflectionClass(InquiryController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(InquiryController::class, 'rejectSpamGeneralInquiry');
    $request = Request::create('/contact', 'POST', $input);

    return $method->invoke($controller, $request);
}

it('rate limits only the public contact submission route', function (): void {
    $contactRoute = Route::getRoutes()->getByName('contact.store');
    $bookingRoute = Route::getRoutes()->getByName('booking.enquiry');

    expect($contactRoute->gatherMiddleware())->toContain('throttle:3,10')
        ->and($bookingRoute->gatherMiddleware())->not->toContain('throttle:3,10');
});

it('discards contact submissions that fill the honeypot', function (): void {
    expect(generalInquirySpamCheck([
        'company_website' => 'https://spam.example',
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
    ]))->toBeTrue();
});

it('discards direct posts without a valid form token', function (): void {
    expect(generalInquirySpamCheck([]))->toBeTrue();
});

it('allows a human-paced submission carrying the encrypted form token', function (): void {
    expect(generalInquirySpamCheck([
        'company_website' => '',
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
    ]))->toBeFalse();
});
