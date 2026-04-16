<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class RequestTransformer implements RequestTransformerInterface
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        //
    }

    /**
     * {@inheritdoc}
     *
     * @param Event $event The Lambda event
     * @param Context $context The Lambda context
     * @return ServerRequestInterface The transformed server request
     * @throws TransformationException If the event cannot be transformed
     */
    public function transform(Event $event, Context $context): ServerRequestInterface
    {

        try {
            $method = isset($event['requestContext']) ? $event['requestContext']['http']['method'] : $event['httpMethod'];
            $path = isset($event['requestContext']) ? $event['requestContext']['http']['path'] : $event['path'];

            $request = $this->requestFactory->createServerRequest($method, $path, (array) $context);
            $request = $request
                ->withAttribute('awsRequestId', $context->awsRequestId)
                ->withAttribute('lambda-context', $context);

            // Add headers
            foreach ((array) ($event['headers'] ?? []) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $request = $request->withCookieParams(
                $this->parseCookieParams((array) ($event['cookies'] ?? []))
            );
            $request = $request->withQueryParams((array) ($event['queryStringParameters'] ?? []));

            // Add body if present
            if (!empty($event['body'])) {
                $request = $request->withBody($this->streamFactory->createStream($event['body']));
            }

            return $request;
        } catch (\Throwable $e) {
            throw TransformationException::forRequest(
                'Failed to transform event to request: ' . $e->getMessage(),
                [
                    'event' => json_encode($event),
                    'context' => [
                        'awsRequestId' => $context->awsRequestId,
                        'functionName' => $context->functionName,
                    ],
                ],
                0,
                $e
            );
        }
    }

    /**
     * Convert Lambda cookie strings like ["a=1", "b=2"] into PSR-7 cookie params.
     *
     * @param array<int, string> $cookies
     * @return array<string, string>
     */
    private function parseCookieParams(array $cookies): array
    {
        $cookieParams = [];

        foreach ($cookies as $cookie) {
            $parts = explode('=', $cookie, 2);
            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }

            $cookieParams[$name] = $parts[1] ?? '';
        }

        return $cookieParams;
    }
}
