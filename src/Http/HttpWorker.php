<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\WorkerInterface;
use Psr\Http\Message\ResponseInterface;
use Phambda\Http\RequestTransformer;
use Phambda\Http\ResponseTransformer;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpWorker implements HttpWorkerInterface
{
    public function __construct(
        private readonly WorkerInterface $worker,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function nextRequest(): ServerRequestInterface
    {
        $invocation = $this->worker->nextInvocation();
        $transformer = new RequestTransformer($this->requestFactory, $this->streamFactory);

        return $transformer->transform($invocation->event, $invocation->context);
    }

    /**
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     */
    public function respond(string $awsInvocationId, ResponseInterface $response): void
    {
        $transformer = new ResponseTransformer();
        $responsePayload = $transformer->transform($response);

        $this->worker->respond($awsInvocationId, json_encode($responsePayload));
    }
}
