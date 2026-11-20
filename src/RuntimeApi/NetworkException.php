<?php

declare(strict_types=1);

namespace Phambda\RuntimeApi;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The request did not reach the Runtime API, or no response came back.
 */
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
