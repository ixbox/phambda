<?php

declare(strict_types=1);

namespace Phambda\Http\ErrorHandler;

use Psr\Http\Message\ResponseInterface;

/**
 * Interface for error handlers in Phambda's HTTP context.
 * 
 * Error handlers are responsible for converting exceptions into appropriate HTTP responses.
 * They should handle both Phambda-specific exceptions and general PHP exceptions.
 */
interface ErrorHandlerInterface
{
    /**
     * Handle an error and generate an appropriate HTTP response.
     *
     * @param \Throwable $error The error to handle
     * @param array<string, mixed> $context Additional context information
     * @return ResponseInterface The response to send back to the client
     */
    public function handle(\Throwable $error, array $context = []): ResponseInterface;
}
