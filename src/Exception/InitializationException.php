<?php

declare(strict_types=1);

namespace Phambda\Exception;

/**
 * Exception thrown when initialization of the Lambda runtime fails.
 */
class InitializationException extends PhambdaException
{
    /**
     * Configuration that was used during initialization.
     *
     * @var array<string, mixed>
     */
    private array $configuration;

    /**
     * @param string $message Error message
     * @param array<string, mixed> $configuration Configuration that was used
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        array $configuration = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->configuration = $configuration;
        $this->addContext('configuration', $configuration);
    }

    /**
     * Get the configuration that was used during initialization.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * Create a new instance with environment information.
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function fromEnvironment(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null
    ): self {
        $envVars = [
            'AWS_LAMBDA_RUNTIME_API',
            'AWS_LAMBDA_FUNCTION_NAME',
            'AWS_LAMBDA_FUNCTION_VERSION',
            'AWS_LAMBDA_FUNCTION_MEMORY_SIZE',
            'AWS_LAMBDA_LOG_GROUP_NAME',
            'AWS_LAMBDA_LOG_STREAM_NAME',
        ];

        $environment = [];
        foreach ($envVars as $var) {
            $value = getenv($var);
            if ($value !== false) {
                $environment[$var] = $value;
            }
        }

        return new self($message, ['environment' => $environment], $code, $previous);
    }
}
