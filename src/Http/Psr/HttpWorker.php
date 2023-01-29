<?php

declare(strict_types=1);

namespace Phambda\Http\Psr;

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
        $method = $invocation->event->requestContext->http->method ??  $invocation->event->httpMethod;
        $path = $invocation->event->requestContext->http->path ?? $invocation->event->path;
        $request = $this->requestFactory->createServerRequest(
            $method,
            $path,
            $invocation->context->toArray(),
        );

        foreach ($invocation->event->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withCookieParams((array) $invocation->event->cookies);
        $request = $request->withQueryParams((array) $invocation->event->queryStringParameters);
        $request = $request->withBody($this->streamFactory->createStream($invocation->event->body));

        return $request;
    }

    /**
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     */
    public function respond(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $this->worker->respond(
            $request->getServerParams()['awsRequestId'],
            json_encode([
                'isBase64Encoded' => false,
                'statusCode' => $response->getStatusCode(),
                'statusDescription' => (string) $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => (string) $response->getBody(),
            ]),
        );
    }
}
