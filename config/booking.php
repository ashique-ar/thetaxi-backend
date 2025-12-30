<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for booking-related settings including
    | payment methods, fees, taxes, and other booking parameters.
    |
    */

    /**
     * Payment Methods
     */
    'payment_methods' => [
        'online' => [
            'enabled' => env('PAYMENT_ONLINE_ENABLED', true),
            'label' => 'Online Payment',
            'description' => 'Pay securely via payment gateway',
            'icon' => 'bi-credit-card-fill',
        ],
        'offline' => [
            'enabled' => env('PAYMENT_OFFLINE_ENABLED', true),
            'label' => 'Pay on Check-in',
            'description' => 'Pay when you collect the vehicle',
            'icon' => 'bi-cash-coin',
        ],
    ],

    /**
     * Payment Gateway Configuration (WebXPay)
     * Uses RSA encryption for secure payment redirect
     */
    'webxpay' => [
        'enabled' => env('WEBXPAY_ENABLED', false),
        'merchant_secret' => env('WEBXPAY_MERCHANT_SECRET', ''), // Secret key for verification
        'public_key' => env('WEBXPAY_PUBLIC_KEY', ''), // RSA public key for encryption
        'api_url' => env('WEBXPAY_API_URL', 'https://tokenize.webxpay.com/v1/api'), // For token-based methods
        'api_username' => env('WEBXPAY_API_USERNAME', ''), // API authentication
        'api_password' => env('WEBXPAY_API_PASSWORD', ''), // API authentication
        'checkout_url' => env('WEBXPAY_CHECKOUT_URL', 'https://webxpay.com/index.php?route=checkout/billing'), // Form POST target
        'return_url' => env('WEBXPAY_RETURN_URL', ''), // Success/failure callback
        'cancel_url' => env('WEBXPAY_CANCEL_URL', ''), // User cancellation
        'notify_url' => env('WEBXPAY_NOTIFY_URL', ''), // Async notification
        'currency' => env('WEBXPAY_CURRENCY', 'LKR'),
    ],

    /**
     * Service Fee Configuration
     */
    'service_fee' => [
        'enabled' => env('BOOKING_SERVICE_FEE_ENABLED', false), // Disabled for now
        'type' => env('BOOKING_SERVICE_FEE_TYPE', 'fixed'), // 'fixed' or 'percentage'
        'amount' => env('BOOKING_SERVICE_FEE_AMOUNT', 0), // Set to 0 for now
        'min_amount' => env('BOOKING_SERVICE_FEE_MIN', 0), // Minimum fee in LKR
        'max_amount' => env('BOOKING_SERVICE_FEE_MAX', null), // Maximum fee in LKR (null = no limit)
    ],

    /**
     * Tax Configuration (Government Tax)
     */
    'tax' => [
        'enabled' => env('BOOKING_TAX_ENABLED', true),
        'rate' => env('BOOKING_TAX_RATE', 0.18), // 18% Government Tax
        'label' => env('BOOKING_TAX_LABEL', 'Gov. Tax'),
        'description' => env('BOOKING_TAX_DESCRIPTION', 'Government Tax'),
    ],

    /**
     * VAT Configuration
     */
    'vat' => [
        'enabled' => env('BOOKING_VAT_ENABLED', false), // Disabled for now
        'rate' => env('BOOKING_VAT_RATE', 0), // 0% VAT for now
        'label' => env('BOOKING_VAT_LABEL', 'VAT'),
        'description' => env('BOOKING_VAT_DESCRIPTION', 'Value Added Tax'),
        'applies_to_service_fee' => env('BOOKING_VAT_APPLIES_TO_SERVICE_FEE', false),
    ],

    /**
     * Advance Payment Configuration
     */
    'advance_payment' => [
        'enabled' => env('BOOKING_ADVANCE_PAYMENT_ENABLED', true),
        'percentage' => env('BOOKING_ADVANCE_PAYMENT_PERCENTAGE', 50), // 50% advance
        'min_amount' => env('BOOKING_ADVANCE_PAYMENT_MIN', 1000), // Minimum advance amount in LKR
    ],

    /**
     * Quotation Configuration
     */
    'quotation' => [
        'enabled' => env('BOOKING_QUOTATION_ENABLED', true),
        'validity_days' => env('BOOKING_QUOTATION_VALIDITY_DAYS', 7),
        'require_approval' => env('BOOKING_QUOTATION_REQUIRE_APPROVAL', true),
    ],

    /**
     * Search expiry - 0 disables expiry. Set in hours.
     */
    'search_expiry_hours' => env('BOOKING_SEARCH_EXPIRY_HOURS', 0),

    /**
     * Booking Status Configuration
     */
    'status' => [
        'draft' => 'draft',
        'pending_payment' => 'pending',
        'pending_approval' => 'pending_approval',
        'payment_processing' => 'payment_processing',
        'confirmed' => 'confirmed',
        'quotation_requested' => 'quotation_requested',
        'quotation_pending' => 'quotation_pending',
        'quotation_sent' => 'quotation_sent',
    ],

    /**
     * Default Currency (for internal calculations)
     */
    'base_currency' => env('BOOKING_BASE_CURRENCY', 'LKR'),

];
