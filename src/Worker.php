<?php

declare(strict_types=1);

namespace Phambda;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

class Worker implements WorkerInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private ?string $baseUri = null,
    ) {
        $awsLambdaRuntimeApi = getenv('AWS_LAMBDA_RUNTIME_API') ?? '127.0.0.1:9001';
        $this->baseUri ??= "http://{$awsLambdaRuntimeApi}/2018-06-01";
    }

    public function nextInvocation(): Invocation
    {
        try {
            $request = $this->requestFactory->createRequest('GET', "{$this->baseUri}/runtime/invocation/next");
            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Failed to fetch next invocation', $response->getStatusCode());
            }

            $event = Event::fromJsonString($response->getBody()->getContents());
            $context = new Context(
                functionName: getenv('AWS_LAMBDA_FUNCTION_NAME') ?: '',
                functionVersion: getenv('AWS_LAMBDA_FUNCTION_VERSION') ?: '',
                invokedFunctionArn: $response->getHeaderLine('Lambda-Runtime-Invoked-Function-Arn') ?? '',
                memoryLimitInMb: getenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE') ?: '',
                awsRequestId: $response->getHeaderLine('Lambda-Runtime-Aws-Request-Id') ?? '',
                logGroupName: getenv('AWS_LAMBDA_LOG_GROUP_NAME') ?: '',
                logStreamName: getenv('AWS_LAMBDA_LOG_STREAM_NAME') ?: '',
                deadlineMs: $response->getHeaderLine('Lambda-Runtime-Deadline-Ms') ?? '',
            );

            return new Invocation($event, $context);
        } catch (ClientExceptionInterface | JsonException | RuntimeException $error) {
            $this->initError($error);
            exit(1);
        }
    }

    public function respond(string $invocationId, string $payload): void
    {
        try {
            $request = $this->requestFactory
                ->createRequest('POST', "{$this->baseUri}/runtime/invocation/{$invocationId}/response")
                ->withBody($this->streamFactory->createStream($payload));

            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() !== 202) {
                throw new RuntimeException('Failed to respond to invocation', $response->getStatusCode());
            }
        } catch (ClientExceptionInterface | RuntimeException $error) {
            $this->error($invocationId, $error);
        }
    }

    public function error(string $invocationId, Throwable $error): void
    {
        try {
            $body = json_encode([
                'errorMessage' => $error->getMessage(),
                'errorType' => get_class($error),
            ]);

            $request = $this->requestFactory
                ->createRequest('POST', "{$this->baseUri}/runtime/invocation/{$invocationId}/error")
                ->withBody($this->streamFactory->createStream($body));

            $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            $this->initError($error);
            exit(1);
        }
    }

    public function initError(Throwable $error): void
    {
        try {
            $body = $this->streamFactory->createStream(json_encode([
                'errorMessage' => $error->getMessage(),
                'errorType' => get_class($error),
            ]));
            $request = $this->requestFactory
                ->createRequest('POST', "{$this->baseUri}/runtime/init/error")
                ->withBody($body)
                ->withHeader('Lambda-Runtime-Function-Error-Type', 'Unhandled');

            $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            error_log($error->getMessage());
            exit(1);
        }
    }
}
