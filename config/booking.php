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
            'description' => 'Pay securely online using WebXPay',
            'icon' => 'bi-credit-card',
        ],
        'bank_transfer' => [
            'enabled' => env('PAYMENT_BANK_TRANSFER_ENABLED', true),
            'label' => 'Bank Transfer',
            'description' => 'Pay via direct bank transfer',
            'icon' => 'bi-bank',
        ],
        'online_banking' => [
            'enabled' => env('PAYMENT_ONLINE_BANKING_ENABLED', true),
            'label' => 'Online Banking',
            'description' => 'Pay using your online banking',
            'icon' => 'bi-wallet2',
        ],
    ],

    /**
     * Payment Gateway Configuration (WebXPay)
     */
    'webxpay' => [
        'enabled' => env('WEBXPAY_ENABLED', false),
        'merchant_id' => env('WEBXPAY_MERCHANT_ID', ''),
        'merchant_secret' => env('WEBXPAY_MERCHANT_SECRET', ''),
        'api_url' => env('WEBXPAY_API_URL', 'https://sandbox.webxpay.com/api'),
        'return_url' => env('WEBXPAY_RETURN_URL', ''),
        'cancel_url' => env('WEBXPAY_CANCEL_URL', ''),
        'notify_url' => env('WEBXPAY_NOTIFY_URL', ''),
        'currency' => env('WEBXPAY_CURRENCY', 'LKR'),
    ],

    /**
     * Service Fee Configuration
     */
    'service_fee' => [
        'enabled' => env('BOOKING_SERVICE_FEE_ENABLED', true),
        'type' => env('BOOKING_SERVICE_FEE_TYPE', 'fixed'), // 'fixed' or 'percentage'
        'amount' => env('BOOKING_SERVICE_FEE_AMOUNT', 750.00), // In LKR for fixed, or percentage value
        'min_amount' => env('BOOKING_SERVICE_FEE_MIN', 0), // Minimum fee in LKR
        'max_amount' => env('BOOKING_SERVICE_FEE_MAX', null), // Maximum fee in LKR (null = no limit)
    ],

    /**
     * Tax Configuration (NBT - Nation Building Tax)
     */
    'tax' => [
        'enabled' => env('BOOKING_TAX_ENABLED', true),
        'rate' => env('BOOKING_TAX_RATE', 0.025), // 2.5% NBT
        'label' => env('BOOKING_TAX_LABEL', 'NBT'),
        'description' => env('BOOKING_TAX_DESCRIPTION', 'Nation Building Tax'),
    ],

    /**
     * VAT Configuration
     */
    'vat' => [
        'enabled' => env('BOOKING_VAT_ENABLED', true),
        'rate' => env('BOOKING_VAT_RATE', 0.18), // 18% VAT in Sri Lanka
        'label' => env('BOOKING_VAT_LABEL', 'VAT'),
        'description' => env('BOOKING_VAT_DESCRIPTION', 'Value Added Tax'),
        'applies_to_service_fee' => env('BOOKING_VAT_APPLIES_TO_SERVICE_FEE', true),
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
