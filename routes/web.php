<?php

use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\Service\ServicePackageController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\FAQController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\Website\CmsController;
use App\Http\Controllers\Website\HomeController;
use App\Http\Controllers\Website\InquiryServicePageController;
use App\Http\Controllers\Website\SitemapController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

// TheTaxi Website Routes
Route::get('/', [HomeController::class, 'index'])->name('home');

// Debug diagnostic endpoints
Route::get('/test', [\App\Http\Controllers\DebugController::class, 'timeoutDiagnostic'])->name('test');
Route::get('/test-render', function () {
    $cmsCount = \App\Models\Website\CmsContent::count();
    $settingsService = new \App\Services\WebsiteSettingsService();
    $settings = $settingsService->getHomepageSettings();

    return view('test', [
        'cmsCount' => $cmsCount,
        'settingsLoaded' => !empty($settings),
        'settings' => $settings
    ]);
})->name('test-render');

// Search routes
Route::get('/search/{id?}', [BookingController::class, 'showResults'])->name('search');


Route::get('/vehicles', function () {
    return view('vehicles');
})->name('vehicles');

// Vehicle routes
Route::get('/vehicle/{id}', [VehicleController::class, 'show'])->name('vehicle.details');
Route::post('/vehicle/{id}/update-pricing', [VehicleController::class, 'updatePricing'])->name('vehicle.updatePricing');

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/contact', [\App\Http\Controllers\Website\ContactController::class, 'index'])->name('contact');

Route::get('/faq', function () {
    return view('faq');
})->name('faq');

// Cart routes
Route::get('/cart', [CartController::class, 'index'])->name('cart');
Route::get('/cart/get', [CartController::class, 'get'])->name('cart.get')->middleware('throttle:60,1'); // 60 requests per minute
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/sync', [CartController::class, 'sync'])->name('cart.sync');
Route::get('/cart/count', [CartController::class, 'count'])->name('cart.count');
Route::patch('/cart/update/{itemKey}', [CartController::class, 'update'])->name('cart.update');
Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');
Route::post('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');
Route::post('/cart/apply-coupon', [CartController::class, 'applyCoupon'])->name('cart.apply-coupon');
Route::post('/cart/remove-coupon', [CartController::class, 'removeCoupon'])->name('cart.remove-coupon');
Route::post('/cart/apply-promo-code', [CartController::class, 'applyPromoCode'])->name('cart.apply-promo-code');
Route::post('/cart/remove-promo-code', [CartController::class, 'removePromoCode'])->name('cart.remove-promo-code');
Route::get('/cart/summary', [CartController::class, 'getSummary'])->name('cart.summary');
Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

// Cart Addon routes
Route::get('/cart/addons/available', [CartController::class, 'getAvailableAddons'])->name('cart.addons.available');
Route::get('/cart/addons/{cartKey}', [CartController::class, 'getItemAddons'])->name('cart.addons.get');
Route::post('/cart/addon/add', [CartController::class, 'addAddon'])->name('cart.addon.add');
Route::post('/cart/addon/remove', [CartController::class, 'removeAddon'])->name('cart.addon.remove');
Route::post('/cart/addon/update-qty', [CartController::class, 'updateAddonQty'])->name('cart.addon.update-qty');
// Bulk update all addon quantities (cart-level)
Route::post('/cart/addons/update-all', [CartController::class, 'updateAllAddons'])->name('cart.addons.update-all');

// Cart Extra KM routes
Route::get('/cart/extra-km/{cartKey}', [CartController::class, 'getExtraKmRate'])->name('cart.extra-km.get');
Route::post('/cart/extra-km/add', [CartController::class, 'addExtraKm'])->name('cart.extra-km.add');
Route::post('/cart/extra-km/remove', [CartController::class, 'removeExtraKm'])->name('cart.extra-km.remove');

// Checkout routes
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
Route::post('/checkout/process', [CheckoutController::class, 'process'])->name('checkout.process');
Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');

// Email link routes for payment and quotation conversion
Route::get('/checkout/payment-resume/{token}', [CheckoutController::class, 'resumePayment'])->name('checkout.payment-resume');
Route::post('/checkout/payment-resume', [CheckoutController::class, 'processPaymentResume'])->name('checkout.process-payment-resume');
Route::get('/checkout/quotation-convert/{token}', [CheckoutController::class, 'convertQuotationToBooking'])->name('checkout.quotation-convert');
Route::get('/checkout/quotation-payment/{token}', [CheckoutController::class, 'quotationToPayment'])->name('checkout.quotation-payment');

// WebXPay payment gateway routes
Route::get('/checkout/webxpay/redirect', [CheckoutController::class, 'webxpayRedirect'])->name('checkout.webxpay.redirect');
Route::get('/checkout/webxpay/callback', [CheckoutController::class, 'webxpayCallback'])->name('checkout.webxpay.callback');
Route::post('/checkout/webxpay/callback', [CheckoutController::class, 'webxpayCallback'])->name('checkout.webxpay.callback.post');
Route::post('/checkout/webxpay/notify', [CheckoutController::class, 'webxpayNotify'])->name('checkout.webxpay.notify');
Route::get('/checkout/webxpay/cancel', [CheckoutController::class, 'webxpayCancel'])->name('checkout.webxpay.cancel');

// Service-specific pages
Route::get('/point-to-point', [BookingController::class, 'pointToPoint'])->name('point-to-point');
Route::get('/corporate-transfers', [InquiryServicePageController::class, 'show'])
    ->defaults('slug', 'corporate-transfers')
    ->name('corporate-transfers');

Route::get('/services/{slug}', [InquiryServicePageController::class, 'show'])
    ->name('inquiry-services.show');

// contact.store
Route::post('/contact', [InquiryController::class, 'store'])->name('contact.store');

// Currency routes
Route::post('/currency/switch', [CurrencyController::class, 'switch'])->name('currency.switch');
Route::get('/currency/available', [CurrencyController::class, 'available'])->name('currency.available');

// Booking routes
// Support GET for search (so public search forms don't require CSRF tokens) while keeping POST for compatibility
Route::match(['get', 'post'], '/booking/search', [BookingController::class, 'search'])->name('booking.search');
Route::post('/booking/enquiry', [InquiryController::class, 'store'])->name('booking.enquiry');
Route::post('/booking/request-quotation', [BookingController::class, 'requestQuotation'])->name('booking.request-quotation');

// Quotation request route (alias for search page modal)
Route::post('/quotation/request', [BookingController::class, 'requestQuotation'])->name('quotation.request');

// Dynamic service configuration API routes
Route::get('/api/services/configuration', [BookingController::class, 'getServiceConfiguration'])->name('api.services.configuration');
Route::get('/api/services/{serviceCode}/form-config', [BookingController::class, 'getServiceFormConfig'])->name('api.services.form-config');
Route::get('/api/services/{serviceCode}/validation-rules', [BookingController::class, 'getServiceValidationRules'])->name('api.services.validation-rules');
Route::get('/api/services/{serviceCode}/packages', [ServicePackageController::class, 'getPackagesByService'])->name('api.services.packages');
// FAQ routes
Route::get('/faq', [FAQController::class, 'index'])->name('faq');
// Route::get('/faq', [FAQController::class, 'index'])->name('faq.index');  
Route::get('/faq/category/{category}', [FAQController::class, 'category'])->name('faq.category');

// Dynamic CMS content routes - these handle all content types dynamically
Route::get('/{contentType}', [CmsController::class, 'index'])
    ->name('cms.index')
    ->where('contentType', '[a-zA-Z0-9-_]+'); // Simple pattern for content types

Route::get('/{contentType}/{content}', [CmsController::class, 'show'])
    ->name('cms.show')
    ->where('contentType', '[a-zA-Z0-9-_]+') // Simple pattern for content types
    ->where('content', '[a-zA-Z0-9-_]+'); // Simple pattern for content slugs

// Sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// File upload routes for admin system
Route::controller(FileUploadController::class)->group(function () {
    Route::get('resources/{path}', 'assets')->where('path', '.*');
});

// Admin system routes
Route::get('/system/{any?}', function () {
    return view('layout.system-app');
})->where('any', '.*');
