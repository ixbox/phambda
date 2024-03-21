<?php

declare(strict_types=1);

namespace Phambda;

use ArrayAccess;
use Error;
use JsonException;
use JsonSerializable;

class Event implements ArrayAccess, JsonSerializable
{
    public function __construct(
        private readonly array $event,
    ) {
    }

    /**
     * @throws JsonException
     */
    public static function fromJsonString(string $json): self
    {
        $event = json_decode($json, flags: JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY);
        return new self($event);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->event[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->event[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new Error("Cannot modify readonly property");
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new Error("Cannot modify readonly property");
    }

    public function jsonSerialize(): mixed
    {
        return $this->event;
    }
}
