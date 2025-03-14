<?php

declare(strict_types=1);

namespace Phambda\Exception;

/**
 * Base exception class for all Phambda-specific exceptions.
 *
 * This class extends RuntimeException and adds context handling capabilities
 * for better error tracking and debugging in AWS Lambda environment.
 */
abstract class PhambdaException extends \RuntimeException
{
    /**
     * Context information for the exception.
     *
     * @var array<string, mixed>
     */
    private array $context = [];

    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Add context information to the exception.
     *
     * @param string $key Context key
     * @param mixed $value Context value
     * @return void
     */
    protected function addContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Get all context information.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get specific context value by key.
     *
     * @param string $key Context key
     * @return mixed|null Context value if exists, null otherwise
     */
    public function getContextValue(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Convert the exception to a string including context information.
     *
     * @return string
     */
    public function __toString(): string
    {
        $baseString = parent::__toString();
        if (empty($this->context)) {
            return $baseString;
        }

        return $baseString . "\nContext: " . json_encode($this->context, JSON_PRETTY_PRINT);
    }
}
