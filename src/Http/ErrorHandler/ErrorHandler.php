<?php

declare(strict_types=1);

namespace Phambda\Http\ErrorHandler;

use Phambda\Exception\PhambdaException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Standard implementation of the error handler interface for HTTP context.
 * 
 * This handler generates standardized JSON error responses with appropriate
 * HTTP status codes and context information based on the environment.
 */
class ErrorHandler implements ErrorHandlerInterface
{
    /**
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory
     * @param bool $debug Whether to include debug information in responses
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly bool $debug = false
    ) {}

    /**
     * @inheritDoc
     */
    public function handle(\Throwable $error, array $context = []): ResponseInterface
    {
        $statusCode = $this->determineStatusCode($error);
        $response = $this->responseFactory->createResponse($statusCode);

        $errorData = [
            'error' => [
                'code' => $this->getErrorCode($error),
                'message' => $error->getMessage(),
            ],
        ];

        // Add context information in debug mode
        if ($this->debug) {
            $errorData['error']['context'] = [
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ];

            // Add exception-specific context if available
            if ($error instanceof PhambdaException) {
                $errorData['error']['context'] = array_merge(
                    $errorData['error']['context'],
                    $error->getContext()
                );
            }

            // Add additional context if provided
            if (!empty($context)) {
                $errorData['error']['context']['additional'] = $context;
            }
        }

        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($errorData, JSON_PRETTY_PRINT));

        return $response;
    }

    /**
     * Determine appropriate HTTP status code for the error.
     *
     * @param \Throwable $error The error to evaluate
     * @return int HTTP status code
     */
    private function determineStatusCode(\Throwable $error): int
    {
        // You might want to map specific exceptions to specific status codes
        return match (true) {
            // Add specific exception mappings here as needed
            $error instanceof \InvalidArgumentException => 400,
            $error instanceof \RuntimeException => 500,
            default => 500,
        };
    }

    /**
     * Get a standardized error code from the exception.
     *
     * @param \Throwable $error The error to evaluate
     * @return string Error code
     */
    private function getErrorCode(\Throwable $error): string
    {
        // Convert exception class name to error code
        $className = basename(str_replace('\\', '/', $error::class));
        return strtoupper((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
    }
}
