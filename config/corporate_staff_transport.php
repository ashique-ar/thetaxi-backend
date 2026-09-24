<?php

return [
    'timezone' => env('CORPORATE_STAFF_TRANSPORT_TIMEZONE', 'Asia/Colombo'),
    'cutoff_minutes_before' => 720,
    'program' => [
        'name' => 'Standard Staff Transport',
        'description' => 'Ready-to-edit starter plan for regular employee transport.',
        'default_opt_mode' => 'opt_out',
    ],
    'shifts' => [
        ['key' => 'morning', 'name' => 'Morning Shift', 'pickup_time' => '08:00', 'dropoff_time' => '17:00', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']],
        ['key' => 'day', 'name' => 'Day Shift', 'pickup_time' => '09:00', 'dropoff_time' => '18:00', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']],
        ['key' => 'evening', 'name' => 'Evening Shift', 'pickup_time' => '14:00', 'dropoff_time' => '22:00', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']],
        ['key' => 'night', 'name' => 'Night Shift', 'pickup_time' => '22:00', 'dropoff_time' => '06:00', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']],
    ],
    'routes' => [
        ['key' => 'home-to-office', 'name' => 'Home to Office', 'direction' => 'pickup'],
        ['key' => 'office-to-home', 'name' => 'Office to Home', 'direction' => 'dropoff'],
    ],
];
