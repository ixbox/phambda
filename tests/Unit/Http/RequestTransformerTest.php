<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Phambda\Context;
use Phambda\Event;
use Phambda\Http\RequestTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class RequestTransformerTest extends TestCase
{
    private ServerRequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private ServerRequestInterface $request;
    private StreamInterface $stream;
    private RequestTransformer $transformer;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(ServerRequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        
        $this->transformer = new RequestTransformer($this->requestFactory, $this->streamFactory);
    }

    public function testTransformWithRequestContextHttpMethod(): void
    {
        $event = new Event([
            'requestContext' => [
                'http' => [
                    'method' => 'POST',
                    'path' => '/api/test'
                ]
            ],
            'headers' => [
                'content-type' => 'application/json'
            ],
            'cookies' => ['session' => 'abc123'],
            'queryStringParameters' => ['param' => 'value'],
            'body' => '{"test":"data"}'
        ]);

        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->requestFactory->method('createServerRequest')
            ->with('POST', '/api/test', (array)$context)
            ->willReturn($this->request);

        $this->request->method('withAttribute')
            ->with('awsRequestId', 'testRequestId')
            ->willReturnSelf();

        $this->request->method('withHeader')
            ->with('content-type', 'application/json')
            ->willReturnSelf();

        $this->request->method('withCookieParams')
            ->with(['session' => 'abc123'])
            ->willReturnSelf();

        $this->request->method('withQueryParams')
            ->with(['param' => 'value'])
            ->willReturnSelf();

        $this->streamFactory->method('createStream')
            ->with('{"test":"data"}')
            ->willReturn($this->stream);

        $this->request->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        $result = $this->transformer->transform($event, $context);
        $this->assertSame($this->request, $result);
    }

    public function testTransformWithHttpMethod(): void
    {
        $event = new Event([
            'httpMethod' => 'GET',
            'path' => '/api/resource',
            'headers' => [
                'accept' => 'application/json'
            ],
            'cookies' => ['session' => 'xyz789'],
            'queryStringParameters' => ['filter' => 'active'],
            'body' => null
        ]);

        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->requestFactory->method('createServerRequest')
            ->with('GET', '/api/resource', (array)$context)
            ->willReturn($this->request);

        $this->request->method('withAttribute')
            ->with('awsRequestId', 'testRequestId')
            ->willReturnSelf();

        $this->request->method('withHeader')
            ->with('accept', 'application/json')
            ->willReturnSelf();

        $this->request->method('withCookieParams')
            ->with(['session' => 'xyz789'])
            ->willReturnSelf();

        $this->request->method('withQueryParams')
            ->with(['filter' => 'active'])
            ->willReturnSelf();

        // body が null の場合は withBody が呼ばれないことを確認
        $this->streamFactory->expects($this->never())->method('createStream');
        $this->request->expects($this->never())->method('withBody');

        $result = $this->transformer->transform($event, $context);
        $this->assertSame($this->request, $result);
    }

    public function testTransformWithEmptyArrays(): void
    {
        $event = new Event([
            'requestContext' => [
                'http' => [
                    'method' => 'DELETE',
                    'path' => '/api/resource/123'
                ]
            ],
            'headers' => [],
            'cookies' => [],
            'queryStringParameters' => [],
            'body' => ''
        ]);

        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->requestFactory->method('createServerRequest')
            ->with('DELETE', '/api/resource/123', (array)$context)
            ->willReturn($this->request);

        $this->request->method('withAttribute')
            ->with('awsRequestId', 'testRequestId')
            ->willReturnSelf();

        $this->request->expects($this->never())->method('withHeader');

        $this->request->method('withCookieParams')
            ->with([])
            ->willReturnSelf();

        $this->request->method('withQueryParams')
            ->with([])
            ->willReturnSelf();

        // body が空文字の場合は withBody が呼ばれないことを確認
        $this->streamFactory->expects($this->never())->method('createStream');
        $this->request->expects($this->never())->method('withBody');

        $result = $this->transformer->transform($event, $context);
        $this->assertSame($this->request, $result);
    }

    public function testTransformWithMultipleHeaders(): void
    {
        $event = new Event([
            'requestContext' => [
                'http' => [
                    'method' => 'PUT',
                    'path' => '/api/update'
                ]
            ],
            'headers' => [
                'content-type' => 'application/json',
                'authorization' => 'Bearer token123',
                'x-api-key' => 'abc123'
            ],
            'cookies' => ['session' => 'def456'],
            'queryStringParameters' => ['id' => '123'],
            'body' => '{"name":"updated"}'
        ]);

        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->requestFactory->method('createServerRequest')
            ->with('PUT', '/api/update', (array)$context)
            ->willReturn($this->request);

        $this->request->method('withAttribute')
            ->with('awsRequestId', 'testRequestId')
            ->willReturnSelf();

        // 複数のヘッダーが正しく処理されることを確認
        // PHPUnit 11では withConsecutive が非推奨のため、別の方法でテスト
        $headerCalls = 0;
        $this->request->method('withHeader')
            ->willReturnCallback(function ($name, $value) use (&$headerCalls) {
                match ($headerCalls++) {
                    0 => $this->assertEquals(['content-type', 'application/json'], [$name, $value]),
                    1 => $this->assertEquals(['authorization', 'Bearer token123'], [$name, $value]),
                    2 => $this->assertEquals(['x-api-key', 'abc123'], [$name, $value]),
                    default => $this->fail('Unexpected call to withHeader')
                };
                return $this->request;
            });

        $this->request->method('withCookieParams')
            ->with(['session' => 'def456'])
            ->willReturnSelf();

        $this->request->method('withQueryParams')
            ->with(['id' => '123'])
            ->willReturnSelf();

        $this->streamFactory->method('createStream')
            ->with('{"name":"updated"}')
            ->willReturn($this->stream);

        $this->request->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        $result = $this->transformer->transform($event, $context);
        $this->assertSame($this->request, $result);
        $this->assertEquals(3, $headerCalls, '3つのヘッダーが処理されるべきです');
    }

    public function testTransformWithNullValues(): void
    {
        $event = new Event([
            'requestContext' => [
                'http' => [
                    'method' => 'GET',
                    'path' => '/api/test'
                ]
            ],
            'headers' => null,
            'cookies' => null,
            'queryStringParameters' => null,
            'body' => null
        ]);

        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->requestFactory->method('createServerRequest')
            ->with('GET', '/api/test', (array)$context)
            ->willReturn($this->request);

        $this->request->method('withAttribute')
            ->with('awsRequestId', 'testRequestId')
            ->willReturnSelf();

        $this->request->expects($this->never())->method('withHeader');

        $this->request->method('withCookieParams')
            ->with([])
            ->willReturnSelf();

        $this->request->method('withQueryParams')
            ->with([])
            ->willReturnSelf();

        $this->streamFactory->expects($this->never())->method('createStream');
        $this->request->expects($this->never())->method('withBody');

        $result = $this->transformer->transform($event, $context);
        $this->assertSame($this->request, $result);
    }
}
