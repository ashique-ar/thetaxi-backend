<?php


it('returns edit permissions inside the canonical edit payload', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));

    expect($controller)
        ->toContain("\$editData['permissions'] = \$permissions;")
        ->toContain("'can_edit_structure' => Gate::allows('update', \$booking)");
});

it('rejects structural booking updates after allocation', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Booking/Traits/BookingSubmissionTrait.php'));

    expect($controller)
        ->toContain("array_key_exists('booking_items', \$params) && !\$structureEditable")
        ->toContain('Trip structure is locked after allocation. Use lifecycle actions for operational changes.')
        ->toContain("['draft', 'pending', 'pending_approval', 'approved', 'confirmed']");
});
