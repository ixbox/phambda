<?php

namespace Phambda\Logger;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

class NanoLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly bool $debugEnabled = false
    ) {
        //
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::DEBUG && !$this->debugEnabled) {
            return;
        }

        $data = [
            'time' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::RFC3339_EXTENDED),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        error_log(json_encode($data));
    }
}
