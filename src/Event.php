<?php

declare(strict_types=1);

namespace Phambda;

use stdClass;

class Event
{
    public function __construct(
        private readonly stdClass $event,
    ) {
    }

    public function __get($name): mixed
    {
        return $this->event->{$name} ?? null;
    }

    public function __isset($name): bool
    {
        return isset($this->event->{$name});
    }

    public function toArray(): array
    {
        return (array) $this->event;
    }

    public static function fromJsonString($json): self
    {
        return new self(json_decode($json));
    }
}
