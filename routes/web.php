<?php

use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\Website\CmsController;
use App\Http\Controllers\Website\HomeController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

// TheTaxi Website Routes
Route::get('/', [HomeController::class, 'index'])->name('home');

// Search routes
Route::get('/search/{id?}', [BookingController::class, 'showResults'])->name('search.results');

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
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/sync', [CartController::class, 'sync'])->name('cart.sync');
Route::get('/cart/count', [CartController::class, 'count'])->name('cart.count');
Route::patch('/cart/update/{itemKey}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/remove/{itemKey}', [CartController::class, 'remove'])->name('cart.remove');
Route::delete('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');
Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

Route::get('/checkout', function () {
    return view('checkout');
})->name('checkout');

// Service-specific pages
Route::get('/point-to-point', [BookingController::class, 'pointToPoint'])->name('point-to-point');
Route::get('/corporate-transfers', [BookingController::class, 'corporateTransfers'])->name('corporate-transfers');

// contact.store
Route::post('/contact', function () {
    // Handle contact form submission
})->name('contact.store');

// Booking routes
Route::post('/booking/search', [BookingController::class, 'search'])->name('booking.search');
Route::post('/booking/enquiry', [BookingController::class, 'enquiry'])->name('booking.enquiry');

// Dynamic service configuration API routes
Route::get('/api/services/configuration', [BookingController::class, 'getServiceConfiguration'])->name('api.services.configuration');
Route::get('/api/services/{serviceCode}/form-config', [BookingController::class, 'getServiceFormConfig'])->name('api.services.form-config');
Route::get('/api/services/{serviceCode}/validation-rules', [BookingController::class, 'getServiceValidationRules'])->name('api.services.validation-rules');

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