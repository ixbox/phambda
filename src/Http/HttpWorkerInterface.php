<?php

namespace Phambda\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface HttpWorkerInterface
{
    public function nextRequest(): ServerRequestInterface;
    public function respond(string $awsInvocationId, ResponseInterface $response): void;
    public function respondWithError(string $awsInvocationId, \Throwable $error): void;
}
