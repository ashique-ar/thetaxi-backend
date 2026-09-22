<?php

namespace App\Logging;

use App\Support\ObservabilitySanitizer;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

class ObservabilityJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        return $this->toJson(ObservabilitySanitizer::values($this->normalize([
            'timestamp' => $record->datetime->format(DATE_ATOM),
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            ...$record->context,
            ...$record->extra,
        ])), true)."\n";
    }
}
