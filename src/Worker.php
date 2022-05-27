<?php

declare(strict_types=1);

namespace Phambda;

use Exception;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

class Worker implements WorkerInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function nextInvocation(): Invocation
    {
        $request = $this->requestFactory->createRequest('get', 'runtime/invocation/next');
        $response = $this->client->sendRequest($request);

        $event = Event::fromJsonString($response->getBody()->getContents());
        $context = new Context(
            functionName: getenv('AWS_LAMBDA_FUNCTION_NAME'),
            functionVersion: getenv('AWS_LAMBDA_FUNCTION_VERSION'),
            invokedFunctionArn: $response->getHeaderLine('Lambda-Runtime-Invoked-Function-Arn'),
            memoryLimitInMb: getenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE'),
            awsRequestId: $response->getHeaderLine('Lambda-Runtime-Aws-Request-Id'),
            logGroupName: getenv('AWS_LAMBDA_LOG_GROUP_NAME'),
            logStreamName: getenv('AWS_LAMBDA_LOG_STREAM_NAME'),
            deadlineMs: $response->getHeaderLine('Lambda-Runtime-Deadline-Ms'),
            traceId: $response->getHeaderLine('Lambda-Runtime-Trace-Id'),
            xAmznTraceId: getenv('_X_AMZN_TRACE_ID'),
            identity: $response->getHeaderLine('Lambda-Runtime-Cognito-Identity'),
            clientContext: $response->getHeaderLine('Lambda-Runtime-Client-Context'),
        );

        return new Invocation($event, $context);
    }

    public function respond(string $invocationId, string $body): void
    {
        $request = $this->requestFactory
            ->createRequest('post', 'runtime/invocation/' . $invocationId . '/response')
            ->withBody($this->streamFactory->createStream($body));

        $this->client->sendRequest($request);
    }

    public function error(string $invocationId, Exception $error): void
    {
        $body = json_encode([
            'errorMessage' => $error->getMessage(),
            'errorType' => get_class($error),
        ]);

        $request = $this->requestFactory
            ->createRequest('post', 'runtime/invocation/' . $invocationId . '/error')
            ->withBody($this->streamFactory->createStream($body));

        $this->client->sendRequest($request);
    }

    public function initError(Exception $error): void
    {
        $body = json_encode([
            'errorMessage' => $error->getMessage(),
            'errorType' => get_class($error),
        ]);

        $request = $this->requestFactory
            ->createRequest('post', 'runtime/init/error')
            ->withBody($this->streamFactory->createStream($body))
            ->withHeader('Lambda-Runtime-Function-Error-Type', 'Unhandled');

        $this->client->sendRequest($request);
    }
}
