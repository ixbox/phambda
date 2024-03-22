<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\WorkerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class HttpWorker implements HttpWorkerInterface
{
    public function __construct(
        private readonly WorkerInterface $worker,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function nextRequest(): ServerRequestInterface
    {
        $invocation = $this->worker->nextInvocation();

        $method = isset($invocation->event['requestContext']) ? $invocation->event['requestContext']['http']['method'] : $invocation->event['httpMethod'];
        $path = isset($invocation->event['requestContext']) ? $invocation->event['requestContext']['http']['path'] : $invocation->event['path'];
        $request = $this->requestFactory->createServerRequest(
            $method,
            $path,
            (array) $invocation->context,
        );
        $request = $request->withAttribute('awsRequestId', $invocation->context['awsRequestId']);
        foreach ((array) $invocation->event['headers'] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $request = $request->withCookieParams((array) $invocation->event['cookies']);
        $request = $request->withQueryParams((array) $invocation->event['queryStringParameters']);
        if ($invocation->event['body']) {
            $request = $request->withBody($this->streamFactory->createStream($invocation->event['body']));
        }

        return $request;
    }

    /**
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     */
    public function respond(string $awsInvocationId, ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        // cookieだけ set-cookie ではなく cookies で返す必要がある
        $cookies = $response->getHeader('set-cookie');
        // headerから set-cookie を取り除く
        $headers = [];
        $multiValueHeaders = [];
        foreach ($response->getHeaders() as $key => $value) {
            $multiValueHeaders[$key] = $value;
            if (strtolower($key) === 'set-cookie') {
                continue;
            }
            $headers[$key] = join(', ', $value);
        }
        $body = $response->getBody()->getContents();

        $this->worker->respond(
            $awsInvocationId,
            json_encode([
                'statusCode' => $statusCode,
                'statusDescription' => '',
                'headers' => $headers,
                'cookies' => $cookies,
                'multiValueHeaders' => $multiValueHeaders,
                'body' => $body,
            ]),
        );
    }
}
