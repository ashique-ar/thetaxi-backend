<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
    ],

    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'http_v1_url' => env('FIREBASE_FCM_HTTP_V1_URL'),
        'queue' => env('FIREBASE_NOTIFICATION_QUEUE', 'driver-notifications'),
        'assignment_title' => env('FIREBASE_ASSIGNMENT_TITLE', 'New Booking Assigned'),
        'assignment_body' => env('FIREBASE_ASSIGNMENT_BODY', 'A new booking has been assigned to you.'),
        'customer_queue' => env('FIREBASE_CUSTOMER_NOTIFICATION_QUEUE', 'customer-notifications'),
        'rider_assignment_title' => env('FIREBASE_RIDER_ASSIGNMENT_TITLE', 'Driver Assigned'),
        'rider_assignment_body' => env('FIREBASE_RIDER_ASSIGNMENT_BODY', 'A driver has been assigned to your booking.'),
    ],

];
