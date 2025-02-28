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
use Psr\Log\LoggerInterface;

class HttpWorker implements HttpWorkerInterface
{
    public function __construct(
        private readonly WorkerInterface $worker,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function nextRequest(): ServerRequestInterface
    {
        $this->logger?->debug('Transforming Lambda event to HTTP request');
        $invocation = $this->worker->nextInvocation();
        $transformer = new RequestTransformer($this->requestFactory, $this->streamFactory);

        $request = $transformer->transform($invocation->event, $invocation->context);
        $this->logger?->info(sprintf(
            'Received HTTP request: %s %s',
            $request->getMethod(),
            $request->getUri()->getPath()
        ));

        return $request;
    }

    /**
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     */
    public function respond(string $awsInvocationId, ResponseInterface $response): void
    {
        $this->logger?->debug('Transforming HTTP response to Lambda response');
        $transformer = new ResponseTransformer();
        $responsePayload = $transformer->transform($response);

        $this->logger?->info(sprintf(
            'Sending HTTP response: %d %s',
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ));
        $this->worker->respond($awsInvocationId, json_encode($responsePayload));
    }
}
