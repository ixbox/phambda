<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\TransformationException;
use Phambda\WorkerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

class HttpWorker implements HttpWorkerInterface
{
    /**
     * @var RequestTransformerInterface
     */
    private readonly RequestTransformerInterface $requestTransformer;

    public function __construct(
        private readonly WorkerInterface $worker,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?LoggerInterface $logger = null,
        ?RequestTransformerInterface $requestTransformer = null,
        private readonly ResponseTransformerInterface $responseTransformer = new ResponseTransformer(),
    ) {
        $this->requestTransformer = $requestTransformer ?? new RequestTransformer($requestFactory, $streamFactory);
    }

    /**
     * Get the next HTTP request from the Lambda runtime.
     *
     * @return ServerRequestInterface
     * @throws TransformationException If the event cannot be transformed to a request
     */
    public function nextRequest(): ServerRequestInterface
    {
        $this->logger?->debug('Transforming Lambda event to HTTP request');
        $invocation = $this->worker->nextInvocation();

        $request = $this->requestTransformer->transform($invocation->event, $invocation->context);
        $this->logger?->info(sprintf(
            'Received HTTP request: %s %s',
            $request->getMethod(),
            $request->getUri()->getPath()
        ));

        return $request;
    }

    /**
     * Send an HTTP response back to the Lambda runtime.
     *
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     * @throws TransformationException If the response cannot be transformed
     */
    public function respond(string $awsInvocationId, ResponseInterface $response): void
    {
        $this->logger?->debug('Transforming HTTP response to Lambda response');
        $responsePayload = $this->responseTransformer->transform($response);

        $this->logger?->info(sprintf(
            'Sending HTTP response: %d %s',
            $response->getStatusCode(),
            $response->getReasonPhrase()
        ));
        $this->worker->respond($awsInvocationId, json_encode($responsePayload));
    }
}
