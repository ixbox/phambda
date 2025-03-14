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
use Psr\Log\NullLogger;

class HttpWorker implements HttpWorkerInterface
{
    public function __construct(
        private readonly WorkerInterface $worker,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RequestTransformerInterface $requestTransformer,
        private readonly ResponseTransformerInterface $responseTransformer,
        public readonly LoggerInterface $logger = new NullLogger(),
    ) {
        //
    }

    public function nextRequest(): ServerRequestInterface
    {
        try {
            $this->logger->debug('Requesting next Lambda invocation');
            $invocation = $this->worker->nextInvocation();

            $request = $this->requestTransformer->transform($invocation->event, $invocation->context);
            $this->logger->info('HTTP request received', [
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'aws_request_id' => $invocation->context->awsRequestId,
            ]);

            return $request;
        } catch (TransformationException $e) {
            $this->logger->error('Failed to transform Lambda event to HTTP request', [
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
            ]);
            throw $e;
        }
    }

    public function respond(string $awsRequestId, ResponseInterface $response): void
    {
        try {
            $this->logger->debug('Transforming HTTP response', [
                'status' => $response->getStatusCode(),
                'aws_request_id' => $awsRequestId,
            ]);

            $responsePayload = $this->responseTransformer->transform($response);

            $this->worker->respond($awsRequestId, json_encode($responsePayload));

            $this->logger->info('HTTP response sent', [
                'status' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
                'aws_request_id' => $awsRequestId,
            ]);
        } catch (TransformationException $e) {
            $this->logger->error('Failed to transform HTTP response', [
                'error' => $e->getMessage(),
                'context' => $e->getContext(),
                'aws_request_id' => $awsRequestId,
            ]);
            throw $e;
        }
    }
}
