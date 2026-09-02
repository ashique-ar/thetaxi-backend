<?php

use App\Http\Controllers\Api\Driver\Mobile\StatusController;

it('keeps the active-hire offline guard owned by the backend', function () {
    $source = file_get_contents((new ReflectionClass(StatusController::class))->getFileName());

    expect($source)
        ->toContain('if ($activeTrip)')
        ->toContain("'error_code' => 'STATUS_ACTIVE_TRIP_REQUIRES_ONLINE'")
        ->toContain("'assignment_id' => \$activeTrip->id")
        ->toContain('], 409);');

    $guardPosition = strpos($source, "'error_code' => 'STATUS_ACTIVE_TRIP_REQUIRES_ONLINE'");
    $endSessionPosition = strpos($source, '$this->sessionService->endSession');

    expect($guardPosition)->not->toBeFalse()
        ->and($endSessionPosition)->not->toBeFalse()
        ->and($guardPosition)->toBeLessThan($endSessionPosition);
});
