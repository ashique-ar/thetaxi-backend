<?php

use App\Http\Controllers\InquiryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;

function generalInquirySpamCheck(array $input): bool
{
    $controller = (new ReflectionClass(InquiryController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(InquiryController::class, 'rejectAutomatedInquiry');
    $request = Request::create('/contact', 'POST', $input);

    return $method->invoke($controller, $request);
}

function inquiryPayloadSpamCheck(array $meta): bool
{
    $controller = (new ReflectionClass(InquiryController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(InquiryController::class, 'rejectSpamOrDuplicatePayload');

    return $method->invoke($controller, Request::create('/contact', 'POST'), $meta);
}

it('rate limits both public inquiry submission routes', function (): void {
    $contactRoute = Route::getRoutes()->getByName('contact.store');
    $bookingRoute = Route::getRoutes()->getByName('booking.enquiry');

    expect($contactRoute->gatherMiddleware())->toContain('throttle:3,10')
        ->and($bookingRoute->gatherMiddleware())->toContain('throttle:5,10');
});

it('renders the shared spam fields in every inquiry form owner', function (): void {
    $formOwners = [
        resource_path('views/contact.blade.php'),
        resource_path('views/corporate-transfers.blade.php'),
        resource_path('views/inquiry/partials/form.blade.php'),
        resource_path('views/components/dynamic-booking-form.blade.php'),
    ];

    foreach ($formOwners as $formOwner) {
        expect(file_get_contents($formOwner))->toContain("inquiry.partials.spam-protection");
    }
});

it('discards contact submissions that fill the honeypot', function (): void {
    expect(generalInquirySpamCheck([
        '_inquiry_website' => 'https://spam.example',
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
    ]))->toBeTrue();
});

it('discards direct posts without a valid form token', function (): void {
    expect(generalInquirySpamCheck([]))->toBeTrue();
});

it('allows a human-paced submission carrying the encrypted form token', function (): void {
    expect(generalInquirySpamCheck([
        '_inquiry_website' => '',
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
    ]))->toBeFalse();
});

it('discards sales solicitation that behaves like a real browser submission', function (): void {
    expect(inquiryPayloadSpamCheck([
        'email' => 'sales@example.com',
        'phone' => '123456789',
        'message' => 'I found SEO opportunities to bring you more customers. I can send a free audit; reply YES.',
    ]))->toBeTrue();
});

it('allows a genuine transport itinerary without sales-spam signals', function (): void {
    expect(inquiryPayloadSpamCheck([
        'email' => 'traveller@example.com',
        'phone' => '+94771234567',
        'message' => 'Please quote airport pickup to Bentota for four passengers and a return trip to Colombo.',
    ]))->toBeFalse();
});

it('discards an exact repeat inquiry for twenty four hours', function (): void {
    $meta = [
        'email' => 'repeat@example.com',
        'phone' => '+94770001122',
        'message' => 'Please quote a van from Colombo to Galle tomorrow.',
    ];

    expect(inquiryPayloadSpamCheck($meta))->toBeFalse()
        ->and(inquiryPayloadSpamCheck($meta))->toBeTrue();
});
