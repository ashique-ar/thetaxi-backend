<?php

return [
    // Enable only after report recipients and mail delivery are verified.
    'scheduled_reports_enabled' => (bool) env('CORPORATE_SCHEDULED_REPORTS_ENABLED', false),
];
