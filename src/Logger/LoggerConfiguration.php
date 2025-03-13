<?php

namespace Phambda\Logger;

use DateTimeInterface;
use DateTimeZone;

class LoggerConfiguration
{
    private DateTimeZone $timezone;

    public function __construct(
        ?string $timezone = null,
        private readonly string $dateFormat = DateTimeInterface::RFC3339_EXTENDED
    ) {
        $this->timezone = new DateTimeZone($timezone ?? $this->getDefaultTimezone());
    }

    private function getDefaultTimezone(): string
    {
        return getenv('LOG_TIMEZONE') ?: 'UTC';
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }
}
