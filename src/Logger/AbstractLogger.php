<?php

namespace Phambda\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

abstract class AbstractLogger implements LoggerInterface
{
    use LoggerTrait;

    private string $minimumLevel;

    public function __construct(
        private readonly LogFormatterInterface $formatter,
        string $minimumLevel = LogLevel::DEBUG,
        private readonly array $defaultContext = []
    ) {
        $this->minimumLevel = $minimumLevel;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog($level)) {
            $data = [
                'time' => date('c'),
                'level' => $level,
                'message' => $message,
                'context' => array_merge($this->defaultContext, $context)
            ];
            error_log($this->formatter->format($data));
        }
    }

    private function shouldLog(string $level): bool
    {
        $levelValue = $this->getLogLevelValue($level);
        $minimumLevelValue = $this->getLogLevelValue($this->minimumLevel);
        return $levelValue >= $minimumLevelValue;
    }

    private function getLogLevelValue(string $level): int
    {
        return match ($level) {
            LogLevel::DEBUG => 100,
            LogLevel::INFO => 200,
            LogLevel::NOTICE => 250,
            LogLevel::WARNING => 300,
            LogLevel::ERROR => 400,
            LogLevel::CRITICAL => 500,
            LogLevel::ALERT => 550,
            LogLevel::EMERGENCY => 600,
            default => 100, // DEBUG
        };
    }
}
