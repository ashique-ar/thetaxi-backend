<?php

it('guards overlapping approved payment finality policies before removing company scope', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000081_make_payment_finality_policy_installation_wide.php'));
    $overlapGuard = strpos($migration, 'Cannot make finality policies installation-wide');
    $companyDrop = strpos($migration, "dropColumn('company_id')");

    expect($overlapGuard)->not->toBeFalse()
        ->and($companyDrop)->not->toBeFalse()
        ->and($overlapGuard)->toBeLessThan($companyDrop)
        ->and($migration)->toContain("unique(['payment_method', 'version'], 'booking_finality_policy_version_unique')");
});
