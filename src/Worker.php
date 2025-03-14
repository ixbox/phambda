<?php

declare(strict_types=1);

namespace Phambda;

use JsonException;
use Phambda\Exception\InitializationException;
use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Phambda\WorkerConfiguration;
use Throwable;

class Worker implements WorkerInterface
{
    private readonly string $baseUri;
    public readonly LoggerInterface $logger;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?WorkerConfiguration $configuration = null
    ) {
        $configuration ??= WorkerConfiguration::fromEnvironment();
        $this->baseUri = $configuration->baseUri;
        $this->logger = $configuration->logger;
        $this->logger->info('Using base URI: ' . $this->baseUri);
    }

    public function nextInvocation(): Invocation
    {
        try {
            $this->logger->debug('Fetching next invocation');
            $request = $this->requestFactory->createRequest('GET', "{$this->baseUri}/runtime/invocation/next");
            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('Received non-200 response: ' . $response->getStatusCode());
                throw new RuntimeException(
                    'Failed to fetch next invocation',
                    $response->getStatusCode()
                );
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

            $this->logger->info('Received invocation: Request ID ' . $context->awsRequestId);
            return new Invocation($event, $context);
        } catch (ClientExceptionInterface | JsonException | RuntimeException $error) {
            $this->initError($error);
            throw InitializationException::fromEnvironment(
                'Failed to get next invocation: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    public function respond(string $invocationId, string $payload): void
    {
        try {
            $this->logger->debug('Sending invocation response: ' . $invocationId);
            $request = $this->requestFactory
                ->createRequest('POST', "{$this->baseUri}/runtime/invocation/{$invocationId}/response")
                ->withBody($this->streamFactory->createStream($payload));

            $response = $this->client->sendRequest($request);
            if ($response->getStatusCode() !== 202) {
                throw RuntimeException::forInvocation(
                    'Failed to respond to invocation',
                    $invocationId,
                    $response->getStatusCode()
                );
            }
        } catch (ClientExceptionInterface | RuntimeException $error) {
            $this->logger->error('Failed to send invocation response', [
                'error' => $error->getMessage(),
                'invocation_id' => $invocationId,
                'type' => $error::class,
            ]);

            // エラー通知の失敗は致命的なため、error()メソッドに委ねる
            $this->error($invocationId, RuntimeException::forInvocation(
                'Failed to send invocation response: ' . $error->getMessage(),
                $invocationId,
                $error->getCode(),
                $error
            ));
        }
    }

    public function error(string $invocationId, Throwable $error): void
    {
        try {
            $this->logger->info('Sending error response for invocation: ' . $invocationId);
            $errorData = [
                'errorMessage' => $error->getMessage(),
                'errorType' => $error::class,
                'stackTrace' => $this->getStackTrace($error),
            ];

            if ($error instanceof PhambdaException && !empty($error->getContext())) {
                $errorData['context'] = $error->getContext();
            }

            $request = $this->requestFactory
                ->createRequest('POST', "{$this->baseUri}/runtime/invocation/{$invocationId}/error")
                ->withBody($this->streamFactory->createStream(json_encode($errorData)))
                ->withHeader('Content-Type', 'application/vnd.aws.lambda.error+json')
                ->withHeader('Lambda-Runtime-Function-Error-Type', $error::class);

            $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            $this->logger->critical('Failed to send error response. Runtime cannot proceed without response.', [
                'error' => $error->getMessage(),
                'invocation_id' => $invocationId,
                'original_error' => $error::class,
            ]);
            throw InitializationException::fromEnvironment(
                'Failed to send error response: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    private function initError(Throwable $error): void
    {
        try {
            $errorData = [
                'errorMessage' => $error->getMessage(),
                'errorType' => $error::class,
                'stackTrace' => $this->getStackTrace($error),
            ];

            if ($error instanceof PhambdaException && !empty($error->getContext())) {
                $errorData['context'] = $error->getContext();
            }

            $request = $this->requestFactory
                ->createRequest('POST', "{$this->baseUri}/runtime/init/error")
                ->withBody($this->streamFactory->createStream(json_encode($errorData)))
                ->withHeader('Content-Type', 'application/vnd.aws.lambda.error+json')
                ->withHeader('Lambda-Runtime-Function-Error-Type', 'Unhandled');

            $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            $this->logger->critical($error->getMessage());
            throw InitializationException::fromEnvironment(
                'Failed to send initialization error: ' . $error->getMessage(),
                0,
                $error
            );
        }
    }

    /**
     * Get formatted stack trace from an error.
     *
     * @param \Throwable $error
     * @return array<string>
     */
    private function getStackTrace(\Throwable $error): array
    {
        return array_map(
            fn ($trace) => sprintf(
                '%s:%d - %s%s%s(%s)',
                $trace['file'] ?? 'unknown',
                $trace['line'] ?? 0,
                $trace['class'] ?? '',
                $trace['type'] ?? '',
                $trace['function'] ?? '',
                implode(', ', array_map(
                    fn ($arg) => is_scalar($arg) ? (string)$arg : gettype($arg),
                    $trace['args'] ?? []
                ))
            ),
            $error->getTrace()
        );
    }
}
