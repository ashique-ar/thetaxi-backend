<?php

$published = array_values(array_filter(array_map('trim', explode(',', (string) env('DRIVER_MOBILE_PUBLISHED_RELEASES', '')))));

return [
    'android_package_id' => 'com.thetaxisl.driver',
    'published_releases' => $published,
    'mandatory_release_validated' => env('DRIVER_MOBILE_MANDATORY_RELEASE_VALIDATED', false),
    'platforms' => [
        'android' => ['latest_build' => (int) env('DRIVER_MOBILE_ANDROID_LATEST_BUILD', 12), 'minimum_supported_build' => (int) env('DRIVER_MOBILE_ANDROID_MINIMUM_BUILD', 1), 'update_url' => env('DRIVER_MOBILE_ANDROID_UPDATE_URL')],
        'ios' => ['latest_build' => (int) env('DRIVER_MOBILE_IOS_LATEST_BUILD', 0), 'minimum_supported_build' => (int) env('DRIVER_MOBILE_IOS_MINIMUM_BUILD', 0), 'update_url' => env('DRIVER_MOBILE_IOS_UPDATE_URL')],
    ],
    'release_notes' => env('DRIVER_MOBILE_RELEASE_NOTES'),
    'manifest' => [
        'version_name' => '1.0.8',
        'build_number' => 12,
        'commit_sha' => env('DRIVER_MOBILE_RELEASE_COMMIT_SHA'),
        'play_track' => env('DRIVER_MOBILE_PLAY_TRACK'),
        'rollout_percentage' => env('DRIVER_MOBILE_ROLLOUT_PERCENTAGE'),
        'release_date' => env('DRIVER_MOBILE_RELEASE_DATE'),
    ],
];
