<?php

declare(strict_types=1);

namespace Phambda\Exception;

use Exception;

/**
 * Base exception class for all Phambda exceptions.
 */
class PhambdaException extends Exception
{
    /**
     * @var array<string, mixed> Additional context information
     */
    protected array $context = [];

    /**
     * Set context information for this exception.
     *
     * @param array<string, mixed> $context
     * @return $this
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Add a single context value.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get the context information.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
