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
            'context' => $context,
        ];

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        error_log($json === false ? '{"message":"Failed to encode log record"}' : $json);
    }
}
