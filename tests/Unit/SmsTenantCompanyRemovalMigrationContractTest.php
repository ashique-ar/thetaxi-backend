<?php

it('removes SMS and booking activity tenant fields without reintroducing them on rollback', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000010_remove_sms_tenant_company_ids.php'));

    expect($migration)
        ->toContain("\$this->dropTenantCompanyId('sms_messages', 'created_at')")
        ->toContain("\$this->dropTenantCompanyId('booking_activities', 'event_at')")
        ->toContain("\$table->dropColumn('company_id')")
        ->toContain('cannot regain tenant company scope')
        ->not->toContain("foreignUuid('company_id')->nullable()");
});
