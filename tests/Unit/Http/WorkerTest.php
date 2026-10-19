<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Phambda\Http\EventFormat;
use Phambda\Http\Invocation;
use Phambda\Http\RequestTransformer;
use Phambda\Http\ResponseTransformer;
use Phambda\Http\Worker;
use Phambda\Worker as EventWorker;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class WorkerTest extends TestCase
{
    private EventWorker $worker;
    private StreamFactoryInterface $streamFactory;
    private ResponseFactoryInterface $responseFactory;
    private ServerRequestInterface $request;
    private ResponseInterface $response;
    private StreamInterface $stream;
    private RequestTransformer $requestTransformer;
    private ResponseTransformer $responseTransformer;
    private Worker $httpWorker;

    protected function setUp(): void
    {
        $this->worker = $this->createMock(EventWorker::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->requestTransformer = $this->createMock(RequestTransformer::class);
        $this->responseTransformer = $this->createMock(ResponseTransformer::class);

        $this->httpWorker = new Worker(
            $this->worker,
            $this->streamFactory,
            $this->responseFactory,
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

        $invocation = new \Phambda\Invocation($event, $context);

        $this->worker->method('nextInvocation')
            ->willReturn($invocation);

        // RequestTransformerがイベントとコンテキストからリクエストを作成することを確認
        $this->requestTransformer->expects($this->atLeastOnce())->method('transform')
            ->with($invocation->event, $invocation->context, EventFormat::V2)
            ->willReturn($this->request);

        $result = $this->httpWorker->nextRequest();
        $this->assertSame($this->request, $result->request);
        $this->assertSame(EventFormat::V2, $result->format);
        $this->assertSame($context, $result->context);
    }

    public function testMalformedEventIsReportedBeforeTheNextRequest(): void
    {
        $badContext = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'bad-id',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );
        $goodContext = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'good-id',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );
        $invocations = [
            new \Phambda\Invocation(new Event(['foo' => 'bar']), $badContext),
            new \Phambda\Invocation(new Event([
                'version' => '2.0',
                'requestContext' => ['http' => ['method' => 'GET', 'path' => '/']],
            ]), $goodContext),
        ];

        $this->worker->method('nextInvocation')->willReturnCallback(function () use (&$invocations) {
            return array_shift($invocations);
        });
        $this->worker->expects($this->once())
            ->method('error')
            ->with('bad-id', $this->isInstanceOf(TransformationException::class));
        $this->requestTransformer->method('transform')->willReturn($this->request);

        $result = $this->httpWorker->nextRequest();

        $this->assertSame($goodContext, $result->context);
        $this->assertSame(EventFormat::V2, $result->format);
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
            ->willReturn([]);

        $this->responseTransformer->expects($this->atLeastOnce())->method('transform')
            ->with($this->response, EventFormat::V2)
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
        // Worker::respond に渡されることを確認
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

        $this->httpWorker->respond($this->httpInvocation($awsInvocationId, EventFormat::V2), $this->response);
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
            ->willReturn($cookies);

        $this->responseTransformer->expects($this->atLeastOnce())->method('transform')
            ->with($this->response, EventFormat::V2)
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
        // Worker::respond に渡されることを確認
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

        $this->httpWorker->respond($this->httpInvocation($awsInvocationId, EventFormat::V2), $this->response);
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
            ->willReturn([]);

        $this->responseTransformer = $this->createMock(ResponseTransformer::class);
        $this->responseTransformer->expects($this->atLeastOnce())->method('transform')
            ->with($this->response, EventFormat::V2)
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

        $this->httpWorker = new Worker(
            $this->worker,
            $this->streamFactory,
            $this->responseFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );
        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // Worker::respond に渡されることを確認
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

        $this->httpWorker->respond($this->httpInvocation($awsInvocationId, EventFormat::V2), $this->response);
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
            ->willReturn([]);

        $this->responseTransformer = $this->createMock(ResponseTransformer::class);
        $this->responseTransformer->expects($this->atLeastOnce())->method('transform')
            ->with($this->response, EventFormat::V2)
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

        $this->httpWorker = new Worker(
            $this->worker,
            $this->streamFactory,
            $this->responseFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );
        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // Worker::respond に渡されることを確認
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

        $this->httpWorker->respond($this->httpInvocation($awsInvocationId, EventFormat::V2), $this->response);
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
            ->willReturn([]);

        $this->responseTransformer = $this->createMock(ResponseTransformer::class);
        $this->responseTransformer->expects($this->atLeastOnce())->method('transform')
            ->with($this->response, EventFormat::V2)
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

        $this->httpWorker = new Worker(
            $this->worker,
            $this->streamFactory,
            $this->responseFactory,
            $this->requestTransformer,
            $this->responseTransformer,
        );
        // ResponseTransformerがレスポンスを変換した結果のJSONが
        // Worker::respond に渡されることを確認
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

        $this->httpWorker->respond($this->httpInvocation($awsInvocationId, EventFormat::V2), $this->response);
    }

    private function worker(?bool $debug): Worker
    {
        return new Worker(
            $this->worker,
            $this->streamFactory,
            $this->responseFactory,
            $this->requestTransformer,
            $this->responseTransformer,
            debug: $debug,
        );
    }

    public function testRespondWithErrorHidesDetailsByDefault(): void
    {
        $invocation = $this->httpInvocation('req-1', EventFormat::V2);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->willReturnSelf();
        $this->response->method('withBody')->willReturnSelf();
        $this->responseFactory->expects($this->atLeastOnce())->method('createResponse')->with(500)->willReturn($this->response);

        // 例外メッセージやスタックトレースをクライアントに返さない
        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with('{"message":"Internal Server Error"}')
            ->willReturn($this->stream);

        $this->responseTransformer->method('transform')->willReturn(['statusCode' => 500]);
        $this->worker->expects($this->once())->method('respond')->with('req-1', '{"statusCode":500}');

        $this->worker(debug: false)->respondWithError($invocation, new \RuntimeException('secret detail'));
    }

    public function testDebugModeDefaultsToPhambdaDebugEnvironmentVariable(): void
    {
        $invocation = $this->httpInvocation('req-1', EventFormat::V2);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withBody')->willReturnSelf();
        $this->responseFactory->method('createResponse')->willReturn($this->response);
        $this->responseTransformer->method('transform')->willReturn(['statusCode' => 500]);

        $bodies = [];
        $this->streamFactory->method('createStream')
            ->willReturnCallback(function (string $body) use (&$bodies) {
                $bodies[] = $body;

                return $this->stream;
            });

        putenv('PHAMBDA_DEBUG=1');
        try {
            $this->worker(debug: null)->respondWithError($invocation, new \RuntimeException('boom'));
        } finally {
            putenv('PHAMBDA_DEBUG');
        }
        $this->worker(debug: null)->respondWithError($invocation, new \RuntimeException('boom'));

        $this->assertStringContainsString('"errorMessage": "boom"', $bodies[0]);
        $this->assertSame('{"message":"Internal Server Error"}', $bodies[1]);
    }

    public function testRespondWithErrorIncludesDetailsInDebugMode(): void
    {
        $invocation = $this->httpInvocation('req-1', EventFormat::V2);
        $this->response->method('getStatusCode')->willReturn(500);
        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/vnd.aws.lambda.error+json')
            ->willReturnSelf();
        $this->response->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        $this->responseFactory->expects($this->once())
            ->method('createResponse')
            ->with(500)
            ->willReturn($this->response);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($this->callback(function (string $json): bool {
                $data = json_decode($json, true);

                return is_array($data)
                    && ($data['errorMessage'] ?? null) === 'boom'
                    && ($data['errorType'] ?? null) === \RuntimeException::class;
            }))
            ->willReturn($this->stream);

        $this->responseTransformer->expects($this->once())
            ->method('transform')
            ->with($this->response, EventFormat::V2)
            ->willReturn(['statusCode' => 500]);

        $this->worker->expects($this->once())
            ->method('respond')
            ->with('req-1', '{"statusCode":500}');

        $this->worker(debug: true)->respondWithError($invocation, new \RuntimeException('boom'));
    }

    public function testRespondUsesTheFormatCarriedByEachInvocation(): void
    {
        $alb = $this->httpInvocation('req-alb', EventFormat::AlbMultiValue);
        $v2 = $this->httpInvocation('req-v2', EventFormat::V2);
        $seen = [];
        $ids = [];

        $this->response->method('getStatusCode')->willReturn(200);
        $this->responseTransformer->expects($this->exactly(2))
            ->method('transform')
            ->willReturnCallback(function (ResponseInterface $response, EventFormat $format) use (&$seen): array {
                $seen[] = $format;

                return ['statusCode' => 200];
            });

        $this->worker->expects($this->exactly(2))
            ->method('respond')
            ->willReturnCallback(function (string $invocationId, string $payload) use (&$ids): void {
                $ids[] = $invocationId;
            });

        $this->httpWorker->respond($alb, $this->response);
        $this->httpWorker->respond($v2, $this->response);

        $this->assertSame([EventFormat::AlbMultiValue, EventFormat::V2], $seen);
        $this->assertSame(['req-alb', 'req-v2'], $ids);
    }

    private function httpInvocation(string $awsRequestId, EventFormat $format): Invocation
    {
        return new Invocation(
            $this->request,
            $format,
            new Context(
                functionName: 'testFunction',
                functionVersion: '1',
                invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
                memoryLimitInMb: '128',
                awsRequestId: $awsRequestId,
                logGroupName: '/aws/lambda/testFunction',
                logStreamName: '2025/02/28/[$LATEST]abcdef',
                deadlineMs: '1234567890',
            ),
        );
    }
}
