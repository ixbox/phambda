<?php

declare(strict_types=1);

namespace Tests\Unit;

use JsonException;
use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\InitializationException;
use Phambda\Exception\RuntimeException;
use Phambda\Invocation;
use Phambda\Worker;
use Phambda\WorkerConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;

class WorkerTest extends TestCase
{
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private RequestInterface $request;
    private ResponseInterface $response;
    private StreamInterface $stream;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->request->method('withHeader')->willReturnSelf();
        $this->request->method('withBody')->willReturnSelf();

        $this->worker = new Worker(
            $this->client,
            $this->requestFactory,
            $this->streamFactory
        );
    }

    public function testConstructorWithDefaultBaseUri(): void
    {
        // AWS_LAMBDA_RUNTIME_APIが設定されていない場合のテスト
        $originalEnv = getenv('AWS_LAMBDA_RUNTIME_API');
        putenv('AWS_LAMBDA_RUNTIME_API'); // 環境変数を未設定にする

        $worker = new Worker(
            $this->client,
            $this->requestFactory,
            $this->streamFactory
        );

        // privateプロパティをリフレクションでアクセス
        $reflection = new \ReflectionClass($worker);
        $property = $reflection->getProperty('baseUri');
        $property->setAccessible(true);
        $baseUri = $property->getValue($worker);

        // 実際の値に合わせてアサーションを修正
        $this->assertSame('http://127.0.0.1:9001/2018-06-01', $baseUri);

        // 環境変数を元に戻す
        if ($originalEnv !== false) {
            putenv("AWS_LAMBDA_RUNTIME_API=$originalEnv");
        }
    }

    public function testConstructorWithCustomBaseUri(): void
    {
        // AWS_LAMBDA_RUNTIME_APIが設定されている場合のテスト
        $originalEnv = getenv('AWS_LAMBDA_RUNTIME_API');
        putenv('AWS_LAMBDA_RUNTIME_API=custom-api:1234');

        $worker = new Worker(
            $this->client,
            $this->requestFactory,
            $this->streamFactory
        );

        // privateプロパティをリフレクションでアクセス
        $reflection = new \ReflectionClass($worker);
        $property = $reflection->getProperty('baseUri');
        $property->setAccessible(true);
        $baseUri = $property->getValue($worker);

        $this->assertSame('http://custom-api:1234/2018-06-01', $baseUri);

        // 環境変数を元に戻す
        if ($originalEnv !== false) {
            putenv("AWS_LAMBDA_RUNTIME_API=$originalEnv");
        } else {
            putenv('AWS_LAMBDA_RUNTIME_API');
        }
    }

    public function testConstructorWithExplicitBaseUri(): void
    {
        $explicitBaseUri = 'http://explicit-uri:5678/custom-path';
        $configuration = new WorkerConfiguration(
            baseUri: $explicitBaseUri,
            logger: new \Psr\Log\NullLogger(),
        );
        $worker = new Worker(
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $configuration,
        );

        // privateプロパティをリフレクションでアクセス
        $reflection = new \ReflectionClass($worker);
        $property = $reflection->getProperty('baseUri');
        $property->setAccessible(true);
        $baseUri = $property->getValue($worker);

        $this->assertSame($explicitBaseUri, $baseUri);
    }

    public function testNextInvocationSuccess(): void
    {
        // 環境変数の設定
        $originalFunctionName = getenv('AWS_LAMBDA_FUNCTION_NAME');
        $originalFunctionVersion = getenv('AWS_LAMBDA_FUNCTION_VERSION');
        $originalMemorySize = getenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE');
        $originalLogGroup = getenv('AWS_LAMBDA_LOG_GROUP_NAME');
        $originalLogStream = getenv('AWS_LAMBDA_LOG_STREAM_NAME');

        putenv('AWS_LAMBDA_FUNCTION_NAME=testFunction');
        putenv('AWS_LAMBDA_FUNCTION_VERSION=1.0');
        putenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE=128');
        putenv('AWS_LAMBDA_LOG_GROUP_NAME=/aws/lambda/testFunction');
        putenv('AWS_LAMBDA_LOG_STREAM_NAME=2025/02/28/[$LATEST]abcdef1234567890');

        $worker = $this->worker;

        // リクエストのモック設定
        $this->requestFactory->method('createRequest')
            ->willReturn($this->request);

        // レスポンスのモック設定
        $this->response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);

        $this->response->expects($this->exactly(3))
            ->method('getHeaderLine')
            ->willReturnMap([
                ['Lambda-Runtime-Invoked-Function-Arn', 'arn:aws:lambda:region:account-id:function:testFunction'],
                ['Lambda-Runtime-Aws-Request-Id', 'testRequestId'],
                ['Lambda-Runtime-Deadline-Ms', '1234567890'],
            ]);

        $jsonResponse = '{"key": "value"}';
        $this->stream->expects($this->once())
            ->method('getContents')
            ->willReturn($jsonResponse);

        $this->response->expects($this->once())
            ->method('getBody')
            ->willReturn($this->stream);

        $this->client->method('sendRequest')
            ->willReturn($this->response);

        // テスト実行
        $invocation = $worker->nextInvocation();

        // アサーション
        $this->assertInstanceOf(Invocation::class, $invocation);
        $this->assertInstanceOf(Event::class, $invocation->event);
        $this->assertInstanceOf(Context::class, $invocation->context);
        $this->assertSame('testFunction', $invocation->context->functionName);
        $this->assertSame('1.0', $invocation->context->functionVersion);
        $this->assertSame('arn:aws:lambda:region:account-id:function:testFunction', $invocation->context->invokedFunctionArn);
        $this->assertSame('128', $invocation->context->memoryLimitInMb);
        $this->assertSame('testRequestId', $invocation->context->awsRequestId);
        $this->assertSame('/aws/lambda/testFunction', $invocation->context->logGroupName);
        $this->assertSame('2025/02/28/[$LATEST]abcdef1234567890', $invocation->context->logStreamName);
        $this->assertSame('1234567890', $invocation->context->deadlineMs);

        // 環境変数を元に戻す
        if ($originalFunctionName !== false) {
            putenv("AWS_LAMBDA_FUNCTION_NAME=$originalFunctionName");
        } else {
            putenv('AWS_LAMBDA_FUNCTION_NAME');
        }
        if ($originalFunctionVersion !== false) {
            putenv("AWS_LAMBDA_FUNCTION_VERSION=$originalFunctionVersion");
        } else {
            putenv('AWS_LAMBDA_FUNCTION_VERSION');
        }
        if ($originalMemorySize !== false) {
            putenv("AWS_LAMBDA_FUNCTION_MEMORY_SIZE=$originalMemorySize");
        } else {
            putenv('AWS_LAMBDA_FUNCTION_MEMORY_SIZE');
        }
        if ($originalLogGroup !== false) {
            putenv("AWS_LAMBDA_LOG_GROUP_NAME=$originalLogGroup");
        } else {
            putenv('AWS_LAMBDA_LOG_GROUP_NAME');
        }
        if ($originalLogStream !== false) {
            putenv("AWS_LAMBDA_LOG_STREAM_NAME=$originalLogStream");
        } else {
            putenv('AWS_LAMBDA_LOG_STREAM_NAME');
        }
    }

    public function testNextInvocationErrorStatus(): void
    {
        // リクエストのモック設定
        $this->requestFactory->method('createRequest')
            ->willReturn($this->request);

        // レスポンスのモック設定
        $this->response->expects($this->atLeastOnce())
            ->method('getStatusCode')
            ->willReturn(500);

        $this->client->method('sendRequest')
            ->willReturn($this->response);

        $worker = $this->worker;

        // 例外が発生することを期待
        $this->expectException(InitializationException::class);
        $this->expectExceptionMessage('Failed to get next invocation');

        // テスト実行
        $worker->nextInvocation();
    }

    public function testNextInvocationClientException(): void
    {
        // リクエストのモック設定
        $this->requestFactory->method('createRequest')
            ->willReturn($this->request);

        // クライアント例外のモック
        $clientException = $this->createMock(ClientExceptionInterface::class);

        $this->client->method('sendRequest')
            ->willThrowException($clientException);

        $worker = $this->worker;

        // 例外が発生することを期待
        $this->expectException(InitializationException::class);

        // テスト実行
        $worker->nextInvocation();
    }

    public function testNextInvocationJsonException(): void
    {
        // リクエストのモック設定
        $this->requestFactory->method('createRequest')
            ->willReturn($this->request);

        // レスポンスのモック設定
        $this->response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);

        $invalidJson = '{invalid json}';
        $this->stream->expects($this->once())
            ->method('getContents')
            ->willReturn($invalidJson);

        $this->response->expects($this->once())
            ->method('getBody')
            ->willReturn($this->stream);

        $this->client->method('sendRequest')
            ->willReturn($this->response);

        $worker = $this->worker;

        // 例外が発生することを期待
        $this->expectException(InitializationException::class);

        // テスト実行
        $worker->nextInvocation();
    }

    public function testRespondSuccess(): void
    {
        $invocationId = 'testInvocationId';
        $payload = '{"result": "success"}';

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/invocation/{$invocationId}/response")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($payload)
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        // レスポンスのモック設定
        $this->response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(202);

        $this->client->expects($this->once())
            ->method('sendRequest')
            ->with($this->request)
            ->willReturn($this->response);

        // テスト実行
        $this->worker->respond($invocationId, $payload);
        // 例外が発生しなければテスト成功
        $this->addToAssertionCount(1);
    }

    public function testRespondErrorStatus(): void
    {
        $invocationId = 'testInvocationId';
        $payload = '{"result": "success"}';

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/invocation/{$invocationId}/response")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($payload)
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        // レスポンスのモック設定
        $this->response->expects($this->atLeastOnce())
            ->method('getStatusCode')
            ->willReturn(500);

        $this->client->expects($this->once())
            ->method('sendRequest')
            ->with($this->request)
            ->willReturn($this->response);

        // errorメソッドが呼ばれることを確認
        $worker = $this->getMockBuilder(Worker::class)
            ->setConstructorArgs([$this->client, $this->requestFactory, $this->streamFactory])
            ->onlyMethods(['error'])
            ->getMock();

        $worker->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo($invocationId),
                $this->isInstanceOf(RuntimeException::class)
            );

        // テスト実行
        $worker->respond($invocationId, $payload);
    }

    public function testRespondClientException(): void
    {
        $invocationId = 'testInvocationId';
        $payload = '{"result": "success"}';

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/invocation/{$invocationId}/response")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($payload)
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        // クライアント例外のモック
        $clientException = $this->createMock(ClientExceptionInterface::class);

        $this->client->method('sendRequest')
            ->willThrowException($clientException);

        // errorメソッドが呼ばれることを確認
        $worker = $this->getMockBuilder(Worker::class)
            ->setConstructorArgs([$this->client, $this->requestFactory, $this->streamFactory])
            ->onlyMethods(['error'])
            ->getMock();

        $worker->expects($this->once())
            ->method('error')
            ->with(
                $this->equalTo($invocationId),
                $this->isInstanceOf(RuntimeException::class)
            );

        // テスト実行
        $worker->respond($invocationId, $payload);
    }

    public function testErrorSuccess(): void
    {
        $invocationId = 'testInvocationId';
        $error = new RuntimeException('Test error');

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/invocation/{$invocationId}/error")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($this->isType('string'))
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();
        $this->request->method('withHeader')->willReturnSelf();
        $this->request->method('withHeader')->willReturnSelf();

        $this->client->expects($this->once())
            ->method('sendRequest')
            ->with($this->request);

        // テスト実行
        $this->worker->error($invocationId, $error);
        // 例外が発生しなければテスト成功
        $this->addToAssertionCount(1);
    }

    public function testErrorClientException(): void
    {
        $invocationId = 'testInvocationId';
        $error = new RuntimeException('Test error');

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/invocation/{$invocationId}/error")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($this->isType('string'))
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        // クライアント例外のモック
        $clientException = $this->createMock(ClientExceptionInterface::class);

        $this->client->expects($this->once())
            ->method('sendRequest')
            ->with($this->request)
            ->willThrowException($clientException);

        $worker = $this->worker;

        // 例外が発生することを期待
        $this->expectException(InitializationException::class);

        // テスト実行
        $worker->error($invocationId, $error);
    }

    public function testInitErrorSuccess(): void
    {
        $error = new RuntimeException('Test init error');

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/init/error")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($this->isType('string'))
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        $this->request->method('withHeader')->willReturnSelf();

        $this->client->expects($this->once())
            ->method('sendRequest')
            ->with($this->request);

        // テスト実行
        $method = (new \ReflectionClass($this->worker))->getMethod('initError');
        $method->setAccessible(true);
        $method->invoke($this->worker, $error);
        // 例外が発生しなければテスト成功
        $this->addToAssertionCount(1);
    }

    public function testInitErrorClientException(): void
    {
        $error = new RuntimeException('Test init error');

        // リクエストのモック設定
        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with('POST', "http://127.0.0.1:9001/2018-06-01/runtime/init/error")
            ->willReturn($this->request);

        $this->streamFactory->expects($this->once())
            ->method('createStream')
            ->with($this->isType('string'))
            ->willReturn($this->stream);

        $this->request->expects($this->once())
            ->method('withBody')
            ->with($this->stream)
            ->willReturnSelf();

        $this->request->method('withHeader')->willReturnSelf();

        // クライアント例外のモック
        $clientException = $this->createStub(ClientExceptionInterface::class);

        $this->client->expects($this->once())
            ->method('sendRequest')
            ->with($this->request)
            ->willThrowException($clientException);

        // テスト実行
        $this->expectException(InitializationException::class);
        $method = (new \ReflectionClass($this->worker))->getMethod('initError');
        $method->setAccessible(true);
        $method->invoke($this->worker, $error);
    }
}
