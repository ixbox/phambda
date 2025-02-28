<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Phambda\Http\ResponseTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ResponseTransformerTest extends TestCase
{
    private ResponseInterface $response;
    private StreamInterface $stream;
    private ResponseTransformer $transformer;

    protected function setUp(): void
    {
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->transformer = new ResponseTransformer();
    }

    public function testTransformBasicResponse(): void
    {
        $this->response->method('getStatusCode')
            ->willReturn(200);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
                'X-Request-Id' => ['abc123']
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->stream->method('getContents')
            ->willReturn('{"message":"success"}');

        $this->response->method('getBody')
            ->willReturn($this->stream);

        $result = $this->transformer->transform($this->response);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'abc123'
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
                'X-Request-Id' => ['abc123']
            ],
            'body' => '{"message":"success"}',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testTransformWithCookies(): void
    {
        $this->response->method('getStatusCode')
            ->willReturn(200);

        $cookies = [
            'session=abc123; Path=/; HttpOnly',
            'preference=dark; Path=/; Max-Age=31536000'
        ];

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
                'Set-Cookie' => $cookies
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn($cookies);

        $this->stream->method('getContents')
            ->willReturn('{"message":"success"}');

        $this->response->method('getBody')
            ->willReturn($this->stream);

        $result = $this->transformer->transform($this->response);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => $cookies,
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
                'Set-Cookie' => $cookies
            ],
            'body' => '{"message":"success"}',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testTransformWithMultipleHeaderValues(): void
    {
        $this->response->method('getStatusCode')
            ->willReturn(200);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
                'Cache-Control' => ['no-cache', 'no-store', 'must-revalidate'],
                'Vary' => ['Accept', 'Accept-Encoding']
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->stream->method('getContents')
            ->willReturn('{"message":"success"}');

        $this->response->method('getBody')
            ->willReturn($this->stream);

        $result = $this->transformer->transform($this->response);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Vary' => 'Accept, Accept-Encoding'
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
                'Cache-Control' => ['no-cache', 'no-store', 'must-revalidate'],
                'Vary' => ['Accept', 'Accept-Encoding']
            ],
            'body' => '{"message":"success"}',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testTransformWithDifferentStatusCodes(): void
    {
        $statusCodes = [201, 204, 400, 404, 500];

        foreach ($statusCodes as $statusCode) {
            $this->response = $this->createMock(ResponseInterface::class);
            $this->stream = $this->createMock(StreamInterface::class);

            $this->response->method('getStatusCode')
                ->willReturn($statusCode);

            $this->response->method('getHeaders')
                ->willReturn([
                    'Content-Type' => ['application/json']
                ]);

            $this->response->method('getHeader')
                ->with('set-cookie')
                ->willReturn([]);

            $this->stream->method('getContents')
                ->willReturn('{"message":"status ' . $statusCode . '"}');

            $this->response->method('getBody')
                ->willReturn($this->stream);

            $result = $this->transformer->transform($this->response);

            $this->assertEquals($statusCode, $result['statusCode']);
            $this->assertEquals('{"message":"status ' . $statusCode . '"}', $result['body']);
        }
    }

    public function testTransformWithEmptyBody(): void
    {
        $this->response->method('getStatusCode')
            ->willReturn(204);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json']
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->stream->method('getContents')
            ->willReturn('');

        $this->response->method('getBody')
            ->willReturn($this->stream);

        $result = $this->transformer->transform($this->response);

        $expected = [
            'statusCode' => 204,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json']
            ],
            'body' => '',
        ];

        $this->assertEquals($expected, $result);
    }

    public function testTransformWithBinaryBody(): void
    {
        $this->response->method('getStatusCode')
            ->willReturn(200);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/octet-stream'],
                'Content-Disposition' => ['attachment; filename="file.bin"']
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $binaryContent = "\x00\x01\x02\x03\x04";
        $this->stream->method('getContents')
            ->willReturn($binaryContent);

        $this->response->method('getBody')
            ->willReturn($this->stream);

        $result = $this->transformer->transform($this->response);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="file.bin"'
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/octet-stream'],
                'Content-Disposition' => ['attachment; filename="file.bin"']
            ],
            'body' => $binaryContent,
        ];

        $this->assertEquals($expected, $result);
    }
}
