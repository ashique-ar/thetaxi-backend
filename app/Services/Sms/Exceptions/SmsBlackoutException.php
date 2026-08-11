<?php

namespace App\Services\Sms\Exceptions;

use DateTimeInterface;
use RuntimeException;

class SmsBlackoutException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly DateTimeInterface $retryAt,
    ) {
        parent::__construct($message);
    }
}
