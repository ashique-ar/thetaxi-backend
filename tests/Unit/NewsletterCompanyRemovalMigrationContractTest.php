<?php

it('reports duplicate newsletter emails before dropping tenant scope without deleting subscriptions', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000017_remove_newsletter_tenant_company.php'));
    $route = file_get_contents(base_path('routes/web.php'));
    $duplicateCheck = strpos($migration, '$duplicateEmails =');
    $normalizeEmails = strpos($migration, "->update(['email' => DB::raw('LOWER(email)')])");
    $dropCompany = strpos($migration, "\$table->dropColumn('company_id')");

    expect($duplicateCheck)->toBeInt();
    expect($normalizeEmails)->toBeInt();
    expect($dropCompany)->toBeInt();
    expect($duplicateCheck)->toBeLessThan($normalizeEmails);
    expect($duplicateCheck)->toBeLessThan($dropCompany);

    expect($migration)
        ->toContain("groupByRaw('LOWER(email)')")
        ->toContain('Reconcile duplicate newsletter subscriber emails')
        ->toContain('$columns[] = \'company_id\'')
        ->not->toContain('->delete()')
        ->toContain('TenantIndexDropper::drop(\'newsletter_subscribers\', $index)')
        ->toContain('$table->dropColumn(\'company_id\')')
        ->toContain('$table->unique(\'email\', \'newsletter_subscribers_installation_email_unique\')')
        ->and($route)
        ->not->toContain('resolveCurrentCompanyId()', '\'company_id\' => $companyId');
});
