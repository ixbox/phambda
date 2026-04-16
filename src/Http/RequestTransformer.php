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
            $method = $this->extractMethod($event);
            $path = $this->extractPath($event);

            $request = $this->requestFactory->createServerRequest($method, $path, (array) $context);
            $request = $request
                ->withAttribute('awsRequestId', $context->awsRequestId)
                ->withAttribute('lambda-context', $context);

            // Add headers
            foreach ($this->normalizeHeaders($event['headers'] ?? []) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $request = $request->withCookieParams(
                $this->parseCookieParams($event['cookies'] ?? [])
            );
            $request = $request->withQueryParams((array) ($event['queryStringParameters'] ?? []));

            // Add body if present
            if (!empty($event['body'])) {
                $request = $request->withBody(
                    $this->streamFactory->createStream($this->normalizeBody($event['body']))
                );
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
     * @param mixed $cookies
     * @return array<string, string>
     */
    private function parseCookieParams(mixed $cookies): array
    {
        if (!is_array($cookies)) {
            return [];
        }

        $cookieParams = [];

        foreach ($cookies as $cookie) {
            if (!is_string($cookie)) {
                continue;
            }

            $parts = explode('=', $cookie, 2);
            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }

            $cookieParams[$name] = $parts[1] ?? '';
        }

        return $cookieParams;
    }

    private function extractMethod(Event $event): string
    {
        $requestContext = $event['requestContext'];
        if (is_array($requestContext)) {
            $http = $requestContext['http'] ?? null;
            if (is_array($http) && is_string($http['method'] ?? null)) {
                return $http['method'];
            }
        }

        if (is_string($event['httpMethod'] ?? null)) {
            return $event['httpMethod'];
        }

        throw TransformationException::forRequest('Request method is missing from event');
    }

    private function extractPath(Event $event): string
    {
        $requestContext = $event['requestContext'];
        if (is_array($requestContext)) {
            $http = $requestContext['http'] ?? null;
            if (is_array($http) && is_string($http['path'] ?? null)) {
                return $http['path'];
            }
        }

        if (is_string($event['path'] ?? null)) {
            return $event['path'];
        }

        throw TransformationException::forRequest('Request path is missing from event');
    }

    /**
     * @param mixed $headers
     * @return array<string, string|array<string>>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($value)) {
                $normalized[$name] = $value;
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $normalizedValues = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $normalizedValues[] = $item;
                }
            }

            $normalized[$name] = $normalizedValues;
        }

        return $normalized;
    }

    private function normalizeBody(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        throw TransformationException::forRequest('Request body must be a string');
    }
}
