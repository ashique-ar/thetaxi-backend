<?php

namespace App\Logging;

use DateTimeZone;
use Monolog\Logger;

class CreateLokiLogger
{
    public function __invoke(array $config): Logger
    {
        return new Logger(
            'loki',
            [new LokiHandler($config)],
            timezone: new DateTimeZone(config('app.timezone', 'UTC')),
        );
    }
}
