<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class RequestTransformer
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        //
    }

    /**
     * @param Event $event The Lambda event
     * @param Context $context The Lambda context
     * @param EventFormat $format The detected HTTP event format
     * @return ServerRequestInterface The transformed server request
     * @throws TransformationException If the event cannot be transformed
     */
    public function transform(Event $event, Context $context, EventFormat $format): ServerRequestInterface
    {
        try {
            $method = $this->extractMethod($event, $format);
            $path = $this->extractPath($event, $format);

            $queryString = $this->extractQueryString($event, $format);
            $uri = $queryString === '' ? $path : $path . '?' . $queryString;

            $request = $this->requestFactory->createServerRequest($method, $uri, (array) $context);
            $request = $request
                ->withAttribute('awsRequestId', $context->awsRequestId)
                ->withAttribute('lambda-context', $context);

            foreach ($this->extractHeaders($event, $format) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $request = $request->withCookieParams($this->extractCookieParams($event, $format));
            parse_str($queryString, $queryParams);
            $request = $request->withQueryParams($queryParams);

            $body = $this->extractBody($event);
            if ($body !== null) {
                $request = $request->withBody($this->streamFactory->createStream($body));
            }

            foreach (['pathParameters', 'stageVariables'] as $attribute) {
                $value = $event[$attribute] ?? null;
                if (is_array($value)) {
                    $request = $request->withAttribute($attribute, $value);
                }
            }

            return $request;
        } catch (TransformationException $e) {
            throw $e;
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
     * @return array<mixed, mixed>
     */
    private function requestContextHttp(Event $event): array
    {
        $requestContext = $event['requestContext'] ?? null;
        if (!is_array($requestContext)) {
            return [];
        }

        $http = $requestContext['http'] ?? null;

        return is_array($http) ? $http : [];
    }

    private function extractMethod(Event $event, EventFormat $format): string
    {
        if ($format === EventFormat::V2) {
            $method = $this->requestContextHttp($event)['method'] ?? null;
            if (is_string($method)) {
                return $method;
            }

            throw TransformationException::forRequest('Request method is missing from event');
        }

        $method = $event['httpMethod'] ?? null;
        if (is_string($method)) {
            return $method;
        }

        throw TransformationException::forRequest('Request method is missing from event');
    }

    private function extractPath(Event $event, EventFormat $format): string
    {
        if ($format === EventFormat::V2) {
            $path = $this->requestContextHttp($event)['path'] ?? null;
            if (is_string($path)) {
                return $path;
            }

            $rawPath = $event['rawPath'] ?? null;
            if (is_string($rawPath)) {
                return $rawPath;
            }

            throw TransformationException::forRequest('Request path is missing from event');
        }

        $path = $event['path'] ?? null;
        if (is_string($path)) {
            return $path;
        }

        throw TransformationException::forRequest('Request path is missing from event');
    }

    /**
     * @return array<string, string|array<string>>
     */
    private function extractHeaders(Event $event, EventFormat $format): array
    {
        $headers = match ($format) {
            EventFormat::V1 => $this->preferMultiValue($event, 'multiValueHeaders', 'headers'),
            EventFormat::AlbMultiValue => $event['multiValueHeaders'] ?? [],
            EventFormat::V2, EventFormat::Alb => $event['headers'] ?? [],
        };

        $headers = $this->normalizeHeaders($headers);

        // Payload format 2.0 moves the Cookie header into the "cookies" array.
        if ($format === EventFormat::V2 && $this->headerValues($headers, 'cookie') === []) {
            $cookies = array_filter((array) ($event['cookies'] ?? []), is_string(...));
            if ($cookies !== []) {
                $headers['cookie'] = implode('; ', $cookies);
            }
        }

        return $headers;
    }

    /**
     * The query string of the request, in URL-encoded form.
     *
     * Payload format 2.0 provides it as rawQueryString. queryStringParameters is not
     * used there because it joins duplicate keys with commas ("a=1&a=2" -> "1,2"),
     * which is indistinguishable from a literal comma. The other formats only provide
     * parsed parameters, so the query string is rebuilt from them. Either way it is
     * parsed with parse_str() afterwards, so every format follows $_GET semantics.
     */
    private function extractQueryString(Event $event, EventFormat $format): string
    {
        if ($format === EventFormat::V2) {
            $raw = $event['rawQueryString'] ?? null;
            if (is_string($raw)) {
                // The function URL documentation shows the example value with a leading "?".
                return ltrim($raw, '?');
            }
        }

        $parameters = match ($format) {
            EventFormat::V1 => $this->preferMultiValue($event, 'multiValueQueryStringParameters', 'queryStringParameters'),
            EventFormat::AlbMultiValue => $event['multiValueQueryStringParameters'] ?? [],
            EventFormat::V2, EventFormat::Alb => $event['queryStringParameters'] ?? [],
        };

        if (!is_array($parameters)) {
            return '';
        }

        $pairs = [];
        foreach ($parameters as $name => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (!is_string($value)) {
                    continue;
                }

                $pairs[] = $this->encodeQueryComponent((string) $name, $format)
                    . '=' . $this->encodeQueryComponent($value, $format);
            }
        }

        return implode('&', $pairs);
    }

    private function encodeQueryComponent(string $component, EventFormat $format): string
    {
        // ALB passes query parameters as they appear in the URL (not decoded).
        if ($format->isAlb()) {
            $component = urldecode($component);
        }

        return rawurlencode($component);
    }

    /**
     * @return array<string, string>
     */
    private function extractCookieParams(Event $event, EventFormat $format): array
    {
        $cookies = match ($format) {
            EventFormat::V2 => $event['cookies'] ?? [],
            EventFormat::V1 => $this->headerValues(
                $this->preferMultiValue($event, 'multiValueHeaders', 'headers'),
                'cookie',
            ),
            EventFormat::Alb => $this->headerValues($event['headers'] ?? [], 'cookie'),
            EventFormat::AlbMultiValue => $this->headerValues($event['multiValueHeaders'] ?? [], 'cookie'),
        };

        return $this->parseCookieParams($cookies);
    }

    /**
     * @return array<mixed, mixed>
     */
    private function preferMultiValue(Event $event, string $multiKey, string $singleKey): array
    {
        $multi = $event[$multiKey] ?? null;
        if (is_array($multi)) {
            return $multi;
        }

        $single = $event[$singleKey] ?? null;

        return is_array($single) ? $single : [];
    }

    /**
     * @return array<int, string>
     */
    private function headerValues(mixed $headers, string $name): array
    {
        if (!is_array($headers)) {
            return [];
        }

        foreach ($headers as $headerName => $value) {
            if (!is_string($headerName) || strcasecmp($headerName, $name) !== 0) {
                continue;
            }

            if (is_string($value)) {
                return [$value];
            }

            if (!is_array($value)) {
                return [];
            }

            $values = [];
            foreach ($value as $item) {
                if (is_string($item)) {
                    $values[] = $item;
                }
            }

            return $values;
        }

        return [];
    }

    /**
     * Parse cookies the way PHP fills $_COOKIE: values are URL-decoded and the
     * first occurrence of a name wins.
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

            foreach (explode(';', $cookie) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                $pieces = explode('=', $part, 2);
                $name = trim($pieces[0]);
                if ($name === '' || array_key_exists($name, $cookieParams)) {
                    continue;
                }

                $cookieParams[$name] = urldecode(trim($pieces[1] ?? ''));
            }
        }

        return $cookieParams;
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

    private function extractBody(Event $event): ?string
    {
        $body = $event['body'] ?? null;
        if ($body === null || $body === '') {
            return null;
        }

        if (!is_string($body)) {
            throw TransformationException::forRequest('Request body must be a string');
        }

        if (($event['isBase64Encoded'] ?? false) !== true) {
            return $body;
        }

        $decoded = base64_decode($body, true);
        if ($decoded === false) {
            throw TransformationException::forRequest('Request body is not valid base64');
        }

        return $decoded;
    }
}
