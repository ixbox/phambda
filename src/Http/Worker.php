<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\PhambdaException;
use Phambda\Exception\TransformationException;
use Phambda\Worker as EventWorker;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Worker
{
    private readonly bool $debug;

    /**
     * @param bool|null $debug Return exception details in error responses. Defaults to the PHAMBDA_DEBUG environment variable.
     */
    public function __construct(
        private readonly EventWorker $worker,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestTransformer $requestTransformer,
        private readonly ResponseTransformer $responseTransformer,
        public readonly LoggerInterface $logger = new NullLogger(),
        ?bool $debug = null,
    ) {
        $this->debug = $debug ?? filter_var(getenv('PHAMBDA_DEBUG'), FILTER_VALIDATE_BOOLEAN);
    }

    public function nextRequest(): Invocation
    {
        while (true) {
            $this->logger->debug('Requesting next Lambda invocation');
            $invocation = $this->worker->nextInvocation();

            try {
                $format = EventFormat::fromEvent($invocation->event);
                $request = $this->requestTransformer->transform($invocation->event, $invocation->context, $format);
                $this->logger->info('HTTP request received', [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'aws_request_id' => $invocation->context->awsRequestId,
                ]);

                return new Invocation($request, $format, $invocation->context);
            } catch (TransformationException $e) {
                $this->logger->error('Failed to transform Lambda event to HTTP request', [
                    'error' => $e->getMessage(),
                    'context' => $e->getContext(),
                    'aws_request_id' => $invocation->context->awsRequestId,
                ]);
                $this->worker->error($invocation->context->awsRequestId, $e);
            }
        }
    }

    public function respond(Invocation $invocation, ResponseInterface $response): void
    {
        $awsRequestId = $invocation->context->awsRequestId;

        try {
            $this->logger->debug('Transforming HTTP response', [
                'status' => $response->getStatusCode(),
                'aws_request_id' => $awsRequestId,
            ]);

            $responsePayload = $this->responseTransformer->transform($response, $invocation->format);
            try {
                $encoded = json_encode($responsePayload, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw TransformationException::forResponse('Failed to encode Lambda response', previous: $e);
            }

            $this->worker->respond($awsRequestId, $encoded);

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

    /**
     * Report an initialization error to /runtime/init/error.
     *
     * @see EventWorker::initError()
     */
    public function initError(\Throwable $error): void
    {
        $this->worker->initError($error);
    }

    /**
     * Respond with HTTP 500.
     *
     * The body only says "Internal Server Error" so that exception messages, stack traces
     * and file paths are not exposed to clients. Set PHAMBDA_DEBUG=1 to include them.
     */
    public function respondWithError(Invocation $invocation, \Throwable $error): void
    {
        if (!$this->debug) {
            $response = $this->responseFactory->createResponse(500)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream('{"message":"Internal Server Error"}'));

            $this->respond($invocation, $response);

            return;
        }

        $errorData = [
            'errorMessage' => $error->getMessage(),
            'errorType' => $error::class,
            'stackTrace' => explode("\n", $error->getTraceAsString()),
        ];

        if ($error instanceof PhambdaException && !empty($error->getContext())) {
            $errorData['context'] = $error->getContext();
        }

        try {
            $errorJson = json_encode($errorData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            $errorJson = '{"errorMessage":"Failed to encode error response"}';
        }

        $response = $this->responseFactory->createResponse(500)
            ->withHeader('Content-Type', 'application/vnd.aws.lambda.error+json')
            ->withBody($this->streamFactory->createStream($errorJson));

        $this->respond($invocation, $response);
    }
}
