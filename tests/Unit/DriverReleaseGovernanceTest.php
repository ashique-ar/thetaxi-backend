<?php

uses(Tests\TestCase::class);

it('aligns the source release manifest package version and build', function () {
    $pubspec = file_get_contents(base_path('../driver-mobile-app/pubspec.yaml'));
    $gradle = file_get_contents(base_path('../driver-mobile-app/android/app/build.gradle.kts'));
    $dialog = file_get_contents(base_path('../driver-mobile-app/lib/modules/taxi_app/presentation/widgets/update_available_dialog.dart'));

    expect(config('driver_mobile_release.android_package_id'))->toBe('com.thetaxisl.driver')
        ->and(config('driver_mobile_release.manifest.version_name'))->toBe('1.0.8')
        ->and(config('driver_mobile_release.manifest.build_number'))->toBe(12)
        ->and($pubspec)->toContain('version: 1.0.8+12')
        ->and($gradle)->toContain('applicationId = "com.thetaxisl.driver"')
        ->and($dialog)->toContain('packageInfo.packageName');
});

it('fails closed when an advertised release is not confirmed published and validated', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Driver/Mobile/AppSettingsController.php'));
    expect(config('driver_mobile_release.published_releases'))->toBe([])
        ->and(config('driver_mobile_release.mandatory_release_validated'))->toBeFalse()
        ->and($controller)->toContain('advertised_release_published')
        ->toContain('mandatory_release_validated');
});
