<?php

namespace App\Logging;

use Monolog\Logger;

class CreateLokiLogger
{
    public function __invoke(array $config): Logger
    {
        return new Logger('loki', [new LokiHandler($config)]);
    }
}
