<?php

it('removes vehicle tenant ownership while retaining installation-wide Wialon uniqueness', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000014_remove_vehicle_tenant_company.php'));

    expect($migration)
        ->toContain("dropColumn('company_id')", "Schema::dropIfExists('vehicle_companies')")
        ->toContain("whereNotNull('wialon_unit_id')", "unique('wialon_unit_id', 'vehicles_wialon_unit_id_unique')")
        ->not->toContain("dropColumn('handover_location')", 'vehicles.company_id contains values');
});
