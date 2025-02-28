<?php

declare(strict_types=1);

namespace Phambda\Http;

use Psr\Http\Message\ResponseInterface;

class ResponseTransformer
{
    public function transform(ResponseInterface $response): array
    {
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
            $headers[$key] = join(', ', $value);
        }

        $body = $response->getBody()->getContents();

        return [
            'statusCode' => $statusCode,
            'statusDescription' => '',
            'headers' => $headers,
            'cookies' => $cookies,
            'multiValueHeaders' => $multiValueHeaders,
            'body' => $body,
        ];
    }
}
