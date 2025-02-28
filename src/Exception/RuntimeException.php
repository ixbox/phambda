<?php

declare(strict_types=1);

namespace Phambda\Exception;

/**
 * Exception thrown during Lambda runtime execution.
 */
class RuntimeException extends PhambdaException
{
    /**
     * @var string|null The invocation ID associated with this exception
     */
    private ?string $invocationId = null;

    /**
     * Set the invocation ID associated with this exception.
     *
     * @param string $invocationId
     * @return $this
     */
    public function setInvocationId(string $invocationId): self
    {
        $this->invocationId = $invocationId;
        $this->addContext('invocation_id', $invocationId);
        return $this;
    }

    /**
     * Get the invocation ID associated with this exception.
     *
     * @return string|null
     */
    public function getInvocationId(): ?string
    {
        return $this->invocationId;
    }

    /**
     * Create a new instance for a specific invocation.
     *
     * @param string $message Error message
     * @param string $invocationId Invocation ID
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function forInvocation(
        string $message,
        string $invocationId,
        int $code = 0,
        ?\Throwable $previous = null
    ): self {
        $exception = new self($message, $code, $previous);
        return $exception->setInvocationId($invocationId);
    }
}
