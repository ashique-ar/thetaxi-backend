<?php

$published = array_values(array_filter(array_map('trim', explode(',', (string) env('DRIVER_MOBILE_PUBLISHED_RELEASES', '')))));

return [
    'android_package_id' => 'com.thetaxisl.driver',
    'published_releases' => $published,
    'mandatory_release_validated' => env('DRIVER_MOBILE_MANDATORY_RELEASE_VALIDATED', false),
    'manifest' => [
        'version_name' => '1.0.8',
        'build_number' => 12,
        'commit_sha' => env('DRIVER_MOBILE_RELEASE_COMMIT_SHA'),
        'play_track' => env('DRIVER_MOBILE_PLAY_TRACK'),
        'rollout_percentage' => env('DRIVER_MOBILE_ROLLOUT_PERCENTAGE'),
        'release_date' => env('DRIVER_MOBILE_RELEASE_DATE'),
    ],
];
