<?php

declare(strict_types=1);

namespace Phambda\Logger;

use DateTimeInterface;
use DateTimeZone;

/**
 * Configuration for Phambda loggers.
 */
class LoggerConfiguration
{
    /**
     * @var DateTimeZone
     */
    private readonly DateTimeZone $timezone;

    /**
     * @param string|null $timezone Timezone name (default: from LOG_TIMEZONE env var or UTC)
     * @param string $dateFormat Date format for log timestamps
     * @param bool $prettyPrint Whether to format JSON logs with pretty print
     * @param bool $includeMemoryUsage Whether to include memory usage in logs
     * @param array<string, bool> $contextFlags Flags to control which context information to include
     */
    public function __construct(
        ?string $timezone = null,
        private readonly string $dateFormat = DateTimeInterface::RFC3339_EXTENDED,
        private readonly bool $prettyPrint = false,
        private readonly bool $includeMemoryUsage = false,
        private readonly array $contextFlags = [
            'lambda_context' => true,
            'request_id' => true,
            'memory_usage' => false,
        ]
    ) {
        $this->timezone = new DateTimeZone($timezone ?? $this->getDefaultTimezone());
    }

    /**
     * Get timezone from environment or fallback to UTC.
     */
    private function getDefaultTimezone(): string
    {
        return getenv('LOG_TIMEZONE') ?: 'UTC';
    }

    /**
     * Get configured timezone.
     */
    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * Get configured date format.
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Get whether pretty printing is enabled for JSON logs.
     */
    public function isPrettyPrintEnabled(): bool
    {
        return $this->prettyPrint;
    }

    /**
     * Get whether memory usage logging is enabled.
     */
    public function isMemoryUsageEnabled(): bool
    {
        return $this->includeMemoryUsage;
    }

    /**
     * Get context flags configuration.
     *
     * @return array<string, bool>
     */
    public function getContextFlags(): array
    {
        return $this->contextFlags;
    }

    /**
     * Create configuration from environment variables.
     */
    public static function fromEnvironment(): self
    {
        return new self(
            getenv('LOG_TIMEZONE') ?: null,
            getenv('LOG_DATE_FORMAT') ?: DateTimeInterface::RFC3339_EXTENDED,
            filter_var(getenv('LOG_PRETTY_PRINT'), FILTER_VALIDATE_BOOLEAN),
            filter_var(getenv('LOG_MEMORY_USAGE'), FILTER_VALIDATE_BOOLEAN),
            [
                'lambda_context' => !filter_var(getenv('LOG_DISABLE_LAMBDA_CONTEXT'), FILTER_VALIDATE_BOOLEAN),
                'request_id' => !filter_var(getenv('LOG_DISABLE_REQUEST_ID'), FILTER_VALIDATE_BOOLEAN),
                'memory_usage' => filter_var(getenv('LOG_MEMORY_USAGE'), FILTER_VALIDATE_BOOLEAN),
            ]
        );
    }
}
