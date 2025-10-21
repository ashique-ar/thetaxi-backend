<?php

use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

// TheTaxi Website Routes
Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/search', function () {
    return view('search');
})->name('search');

Route::get('/services/{type?}', function ($type = null) {
    return view('services', compact('type'));
})->name('services');

Route::get('/vehicles', function () {
    return view('vehicles');
})->name('vehicles');

Route::get('/vehicle/{id}', function ($id) {
    return view('vehicle-details', compact('id'));
})->name('vehicle.details');

Route::get('/about', function () {
    return view('about');
})->name('about');

Route::get('/contact', function () {
    return view('contact');
})->name('contact');

Route::get('/faq', function () {
    return view('faq');
})->name('faq');

Route::get('/cart', function () {
    return view('cart');
})->name('cart');

Route::get('/checkout', function () {
    return view('checkout');
})->name('checkout');

// contact.store
Route::post('/contact', function () {
    // Handle contact form submission
})->name('contact.store');

// Booking routes
Route::post('/booking/search', [BookingController::class, 'search'])->name('booking.search');
Route::post('/booking/enquiry', [BookingController::class, 'enquiry'])->name('booking.enquiry');

// cms type and cms page will user blogs and blog single pages


// blog.single
Route::get('/blog/{id}', function ($id) {
    return view('blog.single', compact('id'));
})->name('blog.single');

// File upload routes for admin system
Route::controller(FileUploadController::class)->group(function () {
    Route::get('resources/{path}', 'assets')->where('path', '.*');
});

// Admin system routes
Route::get('/system/{any?}', function () {
    return view('layout.system-app');
})->where('any', '.*');