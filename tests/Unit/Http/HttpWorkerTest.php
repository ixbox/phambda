<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Phambda\Context;
use Phambda\Event;
use Phambda\Http\HttpWorker;
use Phambda\Http\RequestTransformerInterface;
use Phambda\Http\ResponseTransformerInterface;
use Phambda\Invocation;
use Phambda\WorkerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class HttpWorkerTest extends TestCase
{
    private WorkerInterface $worker;
    private ServerRequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private ServerRequestInterface $request;
    private ResponseInterface $response;
    private StreamInterface $stream;
    private RequestTransformerInterface $requestTransformer;
    private ResponseTransformerInterface $responseTransformer;
    private HttpWorker $httpWorker;

    protected function setUp(): void
    {
        $this->worker = $this->createMock(WorkerInterface::class);
        $this->requestFactory = $this->createMock(ServerRequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->requestTransformer = $this->createMock(RequestTransformerInterface::class);
        $this->responseTransformer = $this->createMock(ResponseTransformerInterface::class);

        $this->httpWorker = new HttpWorker(
            $this->worker,
            $this->requestFactory,
            $this->streamFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );
    }

    public function testNextRequest(): void
    {
        $event = new Event([
            'requestContext' => [
                'http' => [
                    'method' => 'GET',
                    'path' => '/api/test',
                ],
            ],
            'headers' => [
                'content-type' => 'application/json',
            ],
            'cookies' => ['session' => 'abc123'],
            'queryStringParameters' => ['param' => 'value'],
            'body' => null,
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

        $invocation = new Invocation($event, $context);

        $this->worker->method('nextInvocation')
            ->willReturn($invocation);

        // RequestTransformerがイベントとコンテキストからリクエストを作成することを確認
        $this->requestTransformer->method('transform')
            ->with($invocation->event, $invocation->context)
            ->willReturn($this->request);

        $result = $this->httpWorker->nextRequest();
        $this->assertSame($this->request, $result);
    }

    public function testRespond(): void
    {
        $awsInvocationId = 'testInvocationId';

        // レスポンスの設定
        $this->response->method('getStatusCode')
            ->willReturn(200);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
                'X-Request-Id' => ['abc123'],
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->responseTransformer->method('transform')
            ->with($this->response)
            ->willReturn([
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
            ]);

        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // WorkerInterfaceのrespondメソッドに渡されることを確認
        $expectedResponsePayload = json_encode([
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
        ]);

        $this->worker->expects($this->once())
            ->method('respond')
            ->with($awsInvocationId, $expectedResponsePayload);

        $this->httpWorker->respond($awsInvocationId, $this->response);
    }

    public function testRespondWithCookies(): void
    {
        $awsInvocationId = 'testInvocationId';

        // レスポンスの設定（クッキー付き）
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

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn($cookies);

        $this->responseTransformer->method('transform')
            ->with($this->response)
            ->willReturn([
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
            ]);

        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // WorkerInterfaceのrespondメソッドに渡されることを確認
        $expectedResponsePayload = json_encode([
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
        ]);

        $this->worker->expects($this->once())
            ->method('respond')
            ->with($awsInvocationId, $expectedResponsePayload);

        $this->httpWorker->respond($awsInvocationId, $this->response);
    }

    public function testRespondWithStatusCode201(): void
    {
        $statusCode = 201;
        $awsInvocationId = 'testInvocationId' . $statusCode;
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);

        // レスポンスの設定
        $this->response->method('getStatusCode')
            ->willReturn($statusCode);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->responseTransformer = $this->createMock(ResponseTransformerInterface::class);
        $this->responseTransformer->method('transform')
            ->with($this->response)
            ->willReturn([
                'statusCode' => $statusCode,
                'statusDescription' => '',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'cookies' => [],
                'multiValueHeaders' => [
                    'Content-Type' => ['application/json'],
                ],
                'body' => '{"message":"status ' . $statusCode . '"}',
                'isBase64Encoded' => false,
            ]);

        $this->httpWorker = new HttpWorker(
            $this->worker,
            $this->requestFactory,
            $this->streamFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );

        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // WorkerInterfaceのrespondメソッドに渡されることを確認
        $expectedResponsePayload = json_encode([
            'statusCode' => $statusCode,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
            ],
            'body' => '{"message":"status ' . $statusCode . '"}',
            'isBase64Encoded' => false,
        ]);

        $this->worker->expects($this->once())
            ->method('respond')
            ->with($awsInvocationId, $expectedResponsePayload);

        $this->httpWorker->respond($awsInvocationId, $this->response);
    }

    public function testRespondWithStatusCode404(): void
    {
        $statusCode = 404;
        $awsInvocationId = 'testInvocationId' . $statusCode;
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);

        // レスポンスの設定
        $this->response->method('getStatusCode')
            ->willReturn($statusCode);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->responseTransformer = $this->createMock(ResponseTransformerInterface::class);
        $this->responseTransformer->method('transform')
            ->with($this->response)
            ->willReturn([
                'statusCode' => $statusCode,
                'statusDescription' => '',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'cookies' => [],
                'multiValueHeaders' => [
                    'Content-Type' => ['application/json'],
                ],
                'body' => '{"message":"status ' . $statusCode . '"}',
                'isBase64Encoded' => false,
            ]);

        $this->httpWorker = new HttpWorker(
            $this->worker,
            $this->requestFactory,
            $this->streamFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );

        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // WorkerInterfaceのrespondメソッドに渡されることを確認
        $expectedResponsePayload = json_encode([
            'statusCode' => $statusCode,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
            ],
            'body' => '{"message":"status ' . $statusCode . '"}',
            'isBase64Encoded' => false,
        ]);

        $this->worker->expects($this->once())
            ->method('respond')
            ->with($awsInvocationId, $expectedResponsePayload);

        $this->httpWorker->respond($awsInvocationId, $this->response);
    }

    public function testRespondWithStatusCode500(): void
    {
        $statusCode = 500;
        $awsInvocationId = 'testInvocationId' . $statusCode;
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);

        // レスポンスの設定
        $this->response->method('getStatusCode')
            ->willReturn($statusCode);

        $this->response->method('getHeaders')
            ->willReturn([
                'Content-Type' => ['application/json'],
            ]);

        $this->response->method('getHeader')
            ->with('set-cookie')
            ->willReturn([]);

        $this->responseTransformer = $this->createMock(ResponseTransformerInterface::class);
        $this->responseTransformer->method('transform')
            ->with($this->response)
            ->willReturn([
                'statusCode' => $statusCode,
                'statusDescription' => '',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'cookies' => [],
                'multiValueHeaders' => [
                    'Content-Type' => ['application/json'],
                ],
                'body' => '{"message":"status ' . $statusCode . '"}',
                'isBase64Encoded' => false,
            ]);

        $this->httpWorker = new HttpWorker(
            $this->worker,
            $this->requestFactory,
            $this->streamFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );

        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // WorkerInterfaceのrespondメソッドに渡されることを確認
        $expectedResponsePayload = json_encode([
            'statusCode' => $statusCode,
            'statusDescription' => '',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'cookies' => [],
            'multiValueHeaders' => [
                'Content-Type' => ['application/json'],
            ],
            'body' => '{"message":"status ' . $statusCode . '"}',
            'isBase64Encoded' => false,
        ]);

        $this->worker->expects($this->once())
            ->method('respond')
            ->with($awsInvocationId, $expectedResponsePayload);

        $this->httpWorker->respond($awsInvocationId, $this->response);
    }
}
