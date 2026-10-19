<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Phambda\Http\EventFormat;
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
                'X-Request-Id' => ['abc123'],
            ]);

        $this->response->expects($this->atLeastOnce())->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->stream->method('isSeekable')
            ->willReturn(true);
        $this->stream->method('getContents')
            ->willReturn('{"message":"success"}');
        $this->stream->method('__toString')
            ->willReturn('{"message":"success"}');

        $this->response->method('getBody')
            ->willReturn($this->stream);
        $this->response->method('getReasonPhrase')
            ->willReturn('');

        $result = $this->transformer->transform($this->response, EventFormat::V2);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-Id' => 'abc123',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
                'X-Request-Id' => ['abc123'],
            ],
            'body' => '{"message":"success"}',
            'isBase64Encoded' => false,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testTransformWithCookies(): void
    {
        $this->response->method('getStatusCode')
            ->willReturn(200);

        $cookies = [
            'session=abc123; Path=/; HttpOnly',
            'preference=dark; Path=/; Max-Age=31536000',
        ];

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
                'Set-Cookie' => $cookies,
            ]);

        $this->response->expects($this->atLeastOnce())->method('getHeader')
            ->with('set-cookie')
            ->willReturn($cookies);

        $this->stream->method('isSeekable')
            ->willReturn(true);
        $this->stream->method('getContents')
            ->willReturn('{"message":"success"}');
        $this->stream->method('__toString')
            ->willReturn('{"message":"success"}');

        $this->response->method('getBody')
            ->willReturn($this->stream);
        $this->response->method('getReasonPhrase')
            ->willReturn('');

        $result = $this->transformer->transform($this->response, EventFormat::V2);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => $cookies,
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
                'Set-Cookie' => $cookies,
            ],
            'body' => '{"message":"success"}',
            'isBase64Encoded' => false,
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
                'Vary' => ['Accept', 'Accept-Encoding'],
            ]);

        $this->response->expects($this->atLeastOnce())->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->stream->method('isSeekable')
            ->willReturn(true);
        $this->stream->method('getContents')
            ->willReturn('{"message":"success"}');
        $this->stream->method('__toString')
            ->willReturn('{"message":"success"}');

        $this->response->method('getBody')
            ->willReturn($this->stream);
        $this->response->method('getReasonPhrase')
            ->willReturn('');

        $result = $this->transformer->transform($this->response, EventFormat::V2);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Vary' => 'Accept, Accept-Encoding',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
                'Cache-Control' => ['no-cache', 'no-store', 'must-revalidate'],
                'Vary' => ['Accept', 'Accept-Encoding'],
            ],
            'body' => '{"message":"success"}',
            'isBase64Encoded' => false,
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
                    'Content-Type' => ['application/json'],
                ]);

            $this->response->expects($this->atLeastOnce())->method('getHeader')
                ->with('set-cookie')
                ->willReturn([]);

            $this->stream->method('isSeekable')
                ->willReturn(true);
            $this->stream->method('getContents')
                ->willReturn('{"message":"status ' . $statusCode . '"}');
            $this->stream->method('__toString')
                ->willReturn('{"message":"status ' . $statusCode . '"}');

            $this->response->method('getBody')
                ->willReturn($this->stream);
            $this->response->method('getReasonPhrase')
                ->willReturn('');

            $result = $this->transformer->transform($this->response, EventFormat::V2);

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
                'Content-Type' => ['application/json'],
            ]);

        $this->response->expects($this->atLeastOnce())->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->stream->method('isSeekable')
            ->willReturn(true);
        $this->stream->method('getContents')
            ->willReturn('');
        $this->stream->method('__toString')
            ->willReturn('');

        $this->response->method('getBody')
            ->willReturn($this->stream);
        $this->response->method('getReasonPhrase')
            ->willReturn('');

        $result = $this->transformer->transform($this->response, EventFormat::V2);

        $expected = [
            'statusCode' => 204,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
            ],
            'body' => '',
            'isBase64Encoded' => false,
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
                'Content-Disposition' => ['attachment; filename="file.bin"'],
            ]);

        $this->response->expects($this->atLeastOnce())->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $binaryContent = "\x00\x01\x02\x03\x04";
        $this->stream->method('isSeekable')
            ->willReturn(true);
        $this->stream->method('getContents')
            ->willReturn($binaryContent);
        $this->stream->method('__toString')
            ->willReturn($binaryContent);

        $this->response->method('getBody')
            ->willReturn($this->stream);
        $this->response->method('getReasonPhrase')
            ->willReturn('');
        $this->response->expects($this->atLeastOnce())->method('getHeaderLine')
            ->with('Content-Type')
            ->willReturn('application/octet-stream');

        $result = $this->transformer->transform($this->response, EventFormat::V2);

        $expected = [
            'statusCode' => 200,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="file.bin"',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/octet-stream'],
                'Content-Disposition' => ['attachment; filename="file.bin"'],
            ],
            'body' => base64_encode($binaryContent),
            'isBase64Encoded' => true,
        ];

        $this->assertEquals($expected, $result);
    }

    public function testPayloadFormatsShareOneResponseShape(): void
    {
        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2');

        foreach ([EventFormat::V1, EventFormat::V2] as $format) {
            $result = $this->transformer->transform($response, $format);

            $this->assertSame(['a=1', 'b=2'], $result['cookies']);
            $this->assertArrayHasKey('headers', $result);
            $this->assertArrayHasKey('multiValueHeaders', $result);
            $this->assertArrayNotHasKey('Set-Cookie', $result['headers']);
            $this->assertSame(['a=1', 'b=2'], $result['multiValueHeaders']['Set-Cookie']);
        }
    }

    public function testAlbUsesHeadersOnly(): void
    {
        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2');

        $result = $this->transformer->transform($response, EventFormat::Alb);

        $this->assertSame('200 OK', $result['statusDescription']);
        $this->assertSame('b=2', $result['headers']['Set-Cookie']);
        $this->assertArrayNotHasKey('multiValueHeaders', $result);
        $this->assertArrayNotHasKey('cookies', $result);
    }

    public function testAlbMultiValueUsesOnlyMultiValueHeaders(): void
    {
        $response = (new Psr17Factory())->createResponse(201, 'Created')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', 'a=1')
            ->withAddedHeader('Set-Cookie', 'b=2');

        $result = $this->transformer->transform($response, EventFormat::AlbMultiValue);

        $this->assertSame('201 Created', $result['statusDescription']);
        $this->assertSame(['a=1', 'b=2'], $result['multiValueHeaders']['Set-Cookie']);
        $this->assertArrayNotHasKey('headers', $result);
        $this->assertArrayNotHasKey('cookies', $result);
    }

    public function testJsonSuffixIsNotBase64Encoded(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(500)
            ->withHeader('Content-Type', 'application/vnd.aws.lambda.error+json')
            ->withBody($factory->createStream('{"errorMessage":"boom"}'));

        $result = $this->transformer->transform($response, EventFormat::V2);

        $this->assertFalse($result['isBase64Encoded']);
        $this->assertSame('{"errorMessage":"boom"}', $result['body']);
    }

    public function testTextBodyThatIsNotUtf8IsBase64Encoded(): void
    {
        $factory = new Psr17Factory();
        $body = "\x82\xA0";
        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=Shift_JIS')
            ->withBody($factory->createStream($body));

        $result = $this->transformer->transform($response, EventFormat::V2);

        $this->assertTrue($result['isBase64Encoded']);
        $this->assertSame(base64_encode($body), $result['body']);
    }

    /**
     * @return iterable<string, array{EventFormat, list<string>}>
     */
    public static function headerMapFields(): iterable
    {
        yield 'HTTP API / function URL (2.0)' => [EventFormat::V2, ['headers', 'multiValueHeaders']];
        yield 'REST API (1.0)' => [EventFormat::V1, ['headers', 'multiValueHeaders']];
        yield 'ALB' => [EventFormat::Alb, ['headers']];
        yield 'ALB multi-value headers' => [EventFormat::AlbMultiValue, ['multiValueHeaders']];
    }

    /**
     * @param list<string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('headerMapFields')]
    public function testEmptyHeadersAreEncodedAsJsonObject(EventFormat $format, array $fields): void
    {
        $payload = $this->transformer->transform(new \Nyholm\Psr7\Response(204), $format);
        $json = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

        foreach ($fields as $field) {
            // json_encode([]) is "[]"; header maps must be "{}"
            $this->assertInstanceOf(\stdClass::class, $json->{$field}, $field);
        }
    }

    public function testAlbSingleValueWarnsWhenHeaderValuesAreDropped(): void
    {
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $transformer = new ResponseTransformer($logger);
        $response = new \Nyholm\Psr7\Response(200, ['Set-Cookie' => ['a=1', 'b=2'], 'Content-Type' => 'text/plain']);

        $payload = $transformer->transform($response, EventFormat::Alb);

        $this->assertSame('b=2', $payload['headers']['Set-Cookie']);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0][0]);
        $this->assertSame(['header' => 'Set-Cookie', 'dropped' => 1], array_intersect_key($logger->records[0][2], ['header' => 0, 'dropped' => 0]));

        // Multi-value mode keeps every value, so nothing is logged
        $transformer->transform($response, EventFormat::AlbMultiValue);
        $this->assertCount(1, $logger->records);
    }
}
