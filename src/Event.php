<?php

declare(strict_types=1);

namespace Phambda;

use JsonException;
use stdClass;

class Event
{
    public function __construct(
        private readonly stdClass $event,
    ) {
    }

    public function __get(string $name): mixed
    {
        return $this->event->{$name} ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->event->{$name});
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return (array) $this->event;
    }

    /**
     * @throws JsonException
     */
    public static function fromJsonString(string $json): self
    {
        $event = json_decode($json, flags: JSON_THROW_ON_ERROR);
        return new self($event);
    }
}
