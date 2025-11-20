<?php

use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\FAQController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\Website\CmsController;
use App\Http\Controllers\Website\HomeController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

// TheTaxi Website Routes
Route::get('/', [HomeController::class, 'index'])->name('home');

// Search routes
Route::get('/search/{id?}', [BookingController::class, 'showResults'])->name('search');

Route::get('/services/{type?}', function ($type = null) {
    return view('services', compact('type'));
})->name('services');

Route::get('/vehicles', function () {
    return view('vehicles');
})->name('vehicles');

// Vehicle routes
Route::get('/vehicle/{id}', [VehicleController::class, 'show'])->name('vehicle.details');

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/contact', function () {
    return view('contact');
})->name('contact');

Route::get('/faq', function () {
    return view('faq');
})->name('faq');

// Cart routes
Route::get('/cart', [CartController::class, 'index'])->name('cart');
Route::get('/cart/get', [CartController::class, 'get'])->name('cart.get');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/sync', [CartController::class, 'sync'])->name('cart.sync');
Route::get('/cart/count', [CartController::class, 'count'])->name('cart.count');
Route::patch('/cart/update/{itemKey}', [CartController::class, 'update'])->name('cart.update');
Route::post('/cart/update-days', [CartController::class, 'updateDays'])->name('cart.update-days');
Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');
Route::post('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');
Route::post('/cart/apply-coupon', [CartController::class, 'applyCoupon'])->name('cart.apply-coupon');
Route::post('/cart/remove-coupon', [CartController::class, 'removeCoupon'])->name('cart.remove-coupon');
Route::get('/cart/summary', [CartController::class, 'getSummary'])->name('cart.summary');
Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

// Checkout routes
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout');
Route::post('/checkout/process', [CheckoutController::class, 'process'])->name('checkout.process');
Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');

// WebXPay payment gateway callback routes
Route::get('/checkout/webxpay/callback', [CheckoutController::class, 'webxpayCallback'])->name('checkout.webxpay.callback');
Route::post('/checkout/webxpay/notify', [CheckoutController::class, 'webxpayNotify'])->name('checkout.webxpay.notify');
Route::get('/checkout/webxpay/cancel', [CheckoutController::class, 'webxpayCancel'])->name('checkout.webxpay.cancel');

// Service-specific pages
Route::get('/point-to-point', [BookingController::class, 'pointToPoint'])->name('point-to-point');
Route::get('/corporate-transfers', [BookingController::class, 'corporateTransfers'])->name('corporate-transfers');

// contact.store
Route::post('/contact', function () {
    // Handle contact form submission
})->name('contact.store');

// Currency routes
Route::post('/currency/switch', [CurrencyController::class, 'switch'])->name('currency.switch');
Route::get('/currency/available', [CurrencyController::class, 'available'])->name('currency.available');

// Booking routes
Route::post('/booking/search', [BookingController::class, 'search'])->name('booking.search');
Route::post('/booking/enquiry', [BookingController::class, 'enquiry'])->name('booking.enquiry');

// Dynamic service configuration API routes
Route::get('/api/services/configuration', [BookingController::class, 'getServiceConfiguration'])->name('api.services.configuration');
Route::get('/api/services/{serviceCode}/form-config', [BookingController::class, 'getServiceFormConfig'])->name('api.services.form-config');
Route::get('/api/services/{serviceCode}/validation-rules', [BookingController::class, 'getServiceValidationRules'])->name('api.services.validation-rules');

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

// File upload routes for admin system
Route::controller(FileUploadController::class)->group(function () {
    Route::get('resources/{path}', 'assets')->where('path', '.*');
});

// Admin system routes
Route::get('/system/{any?}', function () {
    return view('layout.system-app');
})->where('any', '.*');