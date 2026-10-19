<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\TransformationException;
use Psr\Http\Message\ResponseInterface;

class ResponseTransformer
{
    private const REASON_PHRASES = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /**
     * @param ResponseInterface $response The HTTP response
     * @param EventFormat $format The HTTP event format of the invocation
     * @return array<string, mixed> The transformed Lambda response
     * @throws TransformationException If the response cannot be transformed
     */
    public function transform(ResponseInterface $response, EventFormat $format): array
    {
        try {
            $statusCode = $response->getStatusCode();
            $body = $this->readBody($response);
            $isBase64Encoded = $this->shouldBase64Encode($response, $body);
            if ($isBase64Encoded) {
                $body = base64_encode($body);
            }

            if ($format->isAlb()) {
                return $this->albResponse($response, $format, $statusCode, $body, $isBase64Encoded);
            }

            $cookies = $response->getHeader('set-cookie');
            $headers = [];
            $multiValueHeaders = [];
            /** @var string $key */
            foreach ($response->getHeaders() as $key => $value) {
                $multiValueHeaders[$key] = $value;
                if (strtolower($key) === 'set-cookie') {
                    continue;
                }
                $headers[$key] = implode(', ', $value);
            }

            return [
                'statusCode' => $statusCode,
                'statusDescription' => $response->getReasonPhrase(),
                'headers' => $headers,
                'cookies' => $cookies,
                'multiValueHeaders' => $multiValueHeaders,
                'body' => $body,
                'isBase64Encoded' => $isBase64Encoded,
            ];
        } catch (\Throwable $e) {
            throw TransformationException::forResponse(
                'Failed to transform response: ' . $e->getMessage(),
                [
                    'status_code' => $response->getStatusCode(),
                    'headers' => json_encode($response->getHeaders()),
                ],
                0,
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function albResponse(
        ResponseInterface $response,
        EventFormat $format,
        int $statusCode,
        string $body,
        bool $isBase64Encoded,
    ): array {
        $payload = [
            'isBase64Encoded' => $isBase64Encoded,
            'statusCode' => $statusCode,
            'statusDescription' => $this->statusDescription($response),
            'body' => $body,
        ];

        if ($format === EventFormat::AlbMultiValue) {
            $payload['multiValueHeaders'] = $response->getHeaders();

            return $payload;
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $value) {
            if ($value === []) {
                continue;
            }
            $headers[$name] = $value[array_key_last($value)];
        }
        $payload['headers'] = $headers;

        return $payload;
    }

    private function statusDescription(ResponseInterface $response): string
    {
        $phrase = $response->getReasonPhrase();
        if ($phrase === '') {
            $phrase = self::REASON_PHRASES[$response->getStatusCode()] ?? 'Unknown';
        }

        return $response->getStatusCode() . ' ' . $phrase;
    }

    private function readBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();

            return $stream->getContents();
        }

        return (string) $stream;
    }

    /**
     * Determine if the response body should be Base64 encoded.
     *
     * @param ResponseInterface $response
     * @return bool
     */
    private function shouldBase64Encode(ResponseInterface $response, string $body): bool
    {
        if (preg_match('//u', $body) !== 1) {
            return true;
        }

        $contentType = $response->getHeaderLine('Content-Type');

        if (str_contains($contentType, '+json')) {
            return false;
        }

        // This is an intentionally lightweight heuristic for typical Lambda
        // REST API responses rather than a full MIME classification.
        // Text-based content types don't need Base64 encoding
        $textTypes = [
            'text/',
            'application/json',
            'application/xml',
            'application/javascript',
            'application/xhtml+xml',
        ];

        foreach ($textTypes as $textType) {
            if (str_contains($contentType, $textType)) {
                return false;
            }
        }

        // Default to Base64 encoding for binary content types
        return !empty($contentType);
    }
}
