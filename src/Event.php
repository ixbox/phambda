<?php

declare(strict_types=1);

namespace Phambda;

use ArrayAccess;
use Error;
use JsonException;
use JsonSerializable;

readonly class Event implements ArrayAccess, JsonSerializable
{
    public function __construct(
        private array $event,
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

    /**
     * Whether the event has the key, even when its value is null.
     */
    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->event);
    }

    /**
     * Same semantics as isset() on an array: false when the value is null.
     */
    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset) && !is_int($offset)) {
            return false;
        }

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

    public function jsonSerialize(): array
    {
        return $this->event;
    }
}
