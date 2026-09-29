<?php

use Illuminate\Support\Str;

it('returns saved stop arrival and completion locations with selected-stop distance checks', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/Api/AssignmentController.php');
    $projection = Str::between(
        $source,
        'private function mapPersistedAssignmentStops(',
        'private function extractStopPointsFromBookingItem('
    );

    expect($projection)
        ->toContain('$stop->arrived_latitude')
        ->toContain('$stop->arrived_longitude')
        ->toContain('$stop->arrived_at')
        ->toContain('$stop->completed_latitude')
        ->toContain('$stop->completed_longitude')
        ->toContain('$stop->completed_at')
        ->toContain("'skipped' => ' skipped'")
        ->toContain("'arrived_location_compliance' => \$this->compareLifecycleLocation(\$plannedLocation, \$arrivedLocation)")
        ->toContain("'completed_location_compliance' => \$this->compareLifecycleLocation(\$plannedLocation, \$completedLocation)")
        ->toContain("'skip_reason' => \$stop->skip_reason");
});
