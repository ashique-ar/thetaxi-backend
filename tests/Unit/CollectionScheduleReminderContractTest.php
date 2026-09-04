<?php

it('keeps handler reminders durable, scoped, and retry safe', function (): void {
    $source = file_get_contents(app_path('Console/Commands/ProcessBookingPaymentSchedules.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_133000_create_booking_collection_reminder_deliveries.php'));
    $permissions = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($source)
        ->toContain("'booking_collection_reminder_deliveries'")
        ->toContain('$profile->collection_eligible')
        ->toContain('$workItem->assigned_sales_profile_id')
        ->toContain('DB::transaction')
        ->toContain('lockForUpdate')
        ->toContain("'message' => 'Review the assigned collection task in the Sales workspace.'")
        ->and($migration)
        ->toContain("\$table->string('idempotency_key', 190)->unique()")
        ->toContain('Refusing to drop immutable booking collection reminder delivery evidence.')
        ->and($controller)
        ->toContain("can('sales.collections.customer-contact.view')")
        ->toContain("'contact_access' => \$canViewCustomerContact")
        ->and($permissions)
        ->toContain("'sales.collections.customer-contact.view'");
});
