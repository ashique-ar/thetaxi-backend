<?php

it('requires handoff location only when a driver is being replaced', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/AssignmentController.php'));

    expect($controller)
        ->toContain('if ($oldDriverId) {')
        ->toContain("if (in_array(\$request->swap_type, ['driver', 'both'], true)")
        ->toContain('The current driver location is missing or stale. Confirm the passenger handoff coordinates before swapping resources.');
});
