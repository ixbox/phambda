<?php

declare(strict_types=1);

namespace Phambda\Http\Psr;

use Phambda\Payload;
use Phambda\WorkerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpWorker
{
    public function __construct(
        private WorkerInterface $worker,
        private ServerRequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function nextRequest(): ServerRequestInterface
    {
        $invocation = $this->worker->nextInvocation();
        $method = $invocation->event['requestContext']['http']['method'];
        $path = $invocation->event['requestContext']['http']['path'];
        $request = $this->requestFactory->createServerRequest(
            $method,
            $path,
            $invocation->context,
        );

        foreach ($invocation->event['headers'] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withCookieParams($invocation->event['cookies'] ?? []);
        $request = $request->withQueryParams($invocation->event['queryStringParameters'] ?? []);
        $request = $request->withBody($this->streamFactory->createStream($invocation->event['body']));

        return $request;
    }

    /**
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     */
    public function respond(string $awsInvocationId, ResponseInterface $response): void
    {
        $this->worker->respond(
            new Payload(
                $awsInvocationId,
                json_encode([
                    "statusCode" => $response->getStatusCode(),
                    "headers" => $response->getHeaders(),
                    "body" => (string) $response->getBody(),
                ]),
            ),
        );
    }
}
