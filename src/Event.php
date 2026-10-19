<?php

declare(strict_types=1);

namespace Phambda;

use ArrayAccess;
use Error;
use JsonException;
use JsonSerializable;

/**
 * Offsets other than string or int are treated as missing keys.
 *
 * @implements ArrayAccess<mixed, mixed>
 */
readonly class Event implements ArrayAccess, JsonSerializable
{
    /**
     * @param array<mixed> $event
     */
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
        if (!is_array($event)) {
            throw new JsonException('Lambda event must be a JSON object or array, ' . get_debug_type($event) . ' given');
        }

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
        if (!is_string($offset) && !is_int($offset)) {
            return null;
        }

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

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->event;
    }
}
