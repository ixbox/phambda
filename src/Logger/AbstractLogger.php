<?php

namespace Phambda\Logger;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

abstract class AbstractLogger implements LoggerInterface
{
    use LoggerTrait;

    private string $minimumLevel;
    private DateTimeZone $timezone;
    private string $dateFormat;

    public function __construct(
        private readonly LogFormatterInterface $formatter,
        string $minimumLevel = LogLevel::DEBUG,
        private readonly array $defaultContext = [],
        private readonly ?LoggerConfiguration $config = null
    ) {
        $this->minimumLevel = $minimumLevel;
        $this->timezone = $this->determineTimezone();
        $this->dateFormat = $this->config?->getDateFormat() ?? DateTimeImmutable::RFC3339_EXTENDED;
    }

    private function determineTimezone(): DateTimeZone
    {
        if ($this->config) {
            return $this->config->getTimezone();
        }

        return new DateTimeZone(getenv('LOG_TIMEZONE') ?: 'UTC');
    }

    private function getFormattedTime(): string
    {
        $dateTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $dateTime->setTimezone($this->timezone)->format($this->dateFormat);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->shouldLog($level)) {
            $data = [
                'time' => $this->getFormattedTime(),
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
