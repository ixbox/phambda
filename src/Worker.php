<?php

declare(strict_types=1);

namespace Phambda;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Worker implements WorkerInterface
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function nextInvocation(): Invocation
    {
        $request = $this->requestFactory->createRequest('get', 'runtime/invocation/next');
        $response = $this->client->sendRequest($request);

        return new Invocation(
            json_decode((string) $response->getBody(), true),
            [
                'function_name' => getenv('AWS_LAMBDA_FUNCTION_NAME'),
                'function_version' => getenv('AWS_LAMBDA_FUNCTION_VERSION'),
                'invoked_function_arn' => $response->getHeaderLine('Lambda-Runtime-Invoked-Function-Arn'),
                'memory_limit_in_mb' => getenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE'),
                'aws_request_id' => $response->getHeaderLine('Lambda-Runtime-Aws-Request-Id'),
                'log_group_name' => getenv('AWS_LAMBDA_LOG_GROUP_NAME'),
                'log_stream_name' => getenv('AWS_LAMBDA_LOG_STREAM_NAME'),
                'deadline_ms' => $response->getHeaderLine('Lambda-Runtime-Deadline-Ms'),
                'trace_id' => $response->getHeaderLine('Lambda-Runtime-Trace-Id'),
                'x_amzn_trace_id' => getenv('_X_AMZN_TRACE_ID'),
                'identity' => $response->getHeaderLine('Lambda-Runtime-Cognito-Identity'),
                'client_context' => $response->getHeaderLine('Lambda-Runtime-Client-Context'),
            ],
        );
    }

    public function respond(Payload $payload): void
    {
        $request = $this->requestFactory
            ->createRequest('post', 'runtime/invocation/' . $payload->awsInvocationId . '/response')
            ->withBody($this->streamFactory->createStream($payload->body));

        $this->client->sendRequest($request);
    }
}
