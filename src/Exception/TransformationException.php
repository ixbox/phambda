<?php

declare(strict_types=1);

namespace Phambda\Exception;

/**
 * Exception thrown when transformation of requests or responses fails.
 */
class TransformationException extends PhambdaException
{
    /**
     * @var string The type of transformation that failed (e.g., 'request', 'response')
     */
    private string $transformationType;

    /**
     * @param string $message Error message
     * @param string $transformationType Type of transformation that failed
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        string $transformationType = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->transformationType = $transformationType;
        $this->addContext('transformation_type', $transformationType);
    }

    /**
     * Get the type of transformation that failed.
     *
     * @return string
     */
    public function getTransformationType(): string
    {
        return $this->transformationType;
    }

    /**
     * Create a new instance for request transformation failures.
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Additional context
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function forRequest(
        string $message,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ): self {
        $exception = new self($message, 'request', $code, $previous);
        return $exception->setContext(array_merge($exception->getContext(), $context));
    }

    /**
     * Create a new instance for response transformation failures.
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Additional context
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function forResponse(
        string $message,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ): self {
        $exception = new self($message, 'response', $code, $previous);
        return $exception->setContext(array_merge($exception->getContext(), $context));
    }
}
