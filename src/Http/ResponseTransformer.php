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
     * @param mixed|null $context Optional context data
     * @return array The transformed Lambda response
     * @throws TransformationException If the response cannot be transformed
     */
    public function transform(mixed $response, mixed $context = null): array
    {
        if (!$response instanceof ResponseInterface) {
            throw TransformationException::forResponse(
                'Expected ResponseInterface instance, got ' . (get_debug_type($response)),
                ['response_type' => get_debug_type($response)]
            );
        }

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

            return [
                'statusCode' => $statusCode,
                'statusDescription' => $response->getReasonPhrase(),
                'headers' => $headers,
                'cookies' => $cookies,
                'multiValueHeaders' => $multiValueHeaders,
                'body' => $body,
                'isBase64Encoded' => $this->shouldBase64Encode($response),
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
