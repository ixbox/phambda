<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\TransformationException;
use Psr\Http\Message\ResponseInterface;

class ResponseTransformer implements ResponseTransformerInterface
{
    /**
     * {@inheritdoc}
     *
     * @param ResponseInterface $response The HTTP response
     * @param array|null $context Optional context data
     * @return array The transformed Lambda response
     * @throws TransformationException If the response cannot be transformed
     */
    public function transform(ResponseInterface $response, ?array $context = null): array
    {

        try {
            $statusCode = $response->getStatusCode();
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

            // Get the body content, rewinding the stream if needed
            $body = '';
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
                $body = $stream->getContents();
            } else {
                $body = (string) $stream;
            }

            // Keep text and JSON responses plain for the common REST API path,
            // and only encode responses that look binary.
            $isBase64Encoded = $this->shouldBase64Encode($response);
            if ($isBase64Encoded) {
                $body = base64_encode($body);
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
     * Determine if the response body should be Base64 encoded.
     *
     * @param ResponseInterface $response
     * @return bool
     */
    private function shouldBase64Encode(ResponseInterface $response): bool
    {
        $contentType = $response->getHeaderLine('Content-Type');

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
