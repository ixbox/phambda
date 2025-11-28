<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Phambda\Http\HttpWorker;
use Phambda\Http\RequestTransformerInterface;
use Phambda\Http\ResponseTransformerInterface;
use Phambda\WorkerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class HttpWorkerTest extends TestCase
{
    private $workerMock;
    private $requestFactoryMock;
    private $streamFactoryMock;
    private $requestTransformerMock;
    private $responseTransformerMock;
    private $httpWorker;

    protected function setUp(): void
    {
        $this->workerMock = $this->createMock(WorkerInterface::class);
        $this->requestFactoryMock = $this->createMock(ServerRequestFactoryInterface::class);
        $this->streamFactoryMock = $this->createMock(StreamFactoryInterface::class);
        $this->requestTransformerMock = $this->createMock(RequestTransformerInterface::class);
        $this->responseTransformerMock = $this->createMock(ResponseTransformerInterface::class);

        $this->httpWorker = new HttpWorker(
            $this->workerMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
            $this->requestTransformerMock,
            $this->responseTransformerMock,
        );
    }

    public function testNextRequest()
    {
        $event = new \Phambda\Event([
            'httpMethod' => 'GET',
            'path' => '/test',
            'headers' => ['Content-Type' => 'application/json'],
            'cookies' => ['sessionId' => 'abc123'],
            'queryStringParameters' => ['param' => 'value'],
            'body' => '{"key":"value"}',
        ]);

        $contextMock = new \Phambda\Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: '12345',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890',
        );

        $invocation = new \Phambda\Invocation($event, $contextMock);

        $this->workerMock->method('nextInvocation')->willReturn($invocation);

        $requestMock = $this->createMock(ServerRequestInterface::class);
        $requestMock->method('withAttribute')->willReturnSelf();
        $requestMock->method('withHeader')->willReturnSelf();
        $requestMock->method('withCookieParams')->willReturnSelf();
        $requestMock->method('withQueryParams')->willReturnSelf();
        $requestMock->method('withBody')->willReturnSelf();

        $this->requestFactoryMock->method('createServerRequest')->willReturn($requestMock);
        $this->streamFactoryMock->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $request = $this->httpWorker->nextRequest();

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
    }

    public function testRespond()
    {
        $awsInvocationId = '12345';
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getHeader')->willReturn(['set-cookie' => 'sessionId=abc123']);
        $responseMock->method('getHeaders')->willReturn(['Content-Type' => ['application/json']]);
        $responseMock->method('getBody')->willReturn($this->createMock(StreamInterface::class));
        $responseMock->method('getReasonPhrase')->willReturn('OK');
        $responseMock->method('getHeaderLine')->willReturn('');

        $this->responseTransformerMock->method('transform')
            ->with($responseMock)
            ->willReturn([
                'statusCode' => 200,
                'statusDescription' => 'OK',
                'headers' => ['Content-Type' => 'application/json'],
                'cookies' => ['sessionId=abc123'],
                'multiValueHeaders' => ['Content-Type' => ['application/json']],
                'body' => '',
                'isBase64Encoded' => false,
            ]);

        $this->workerMock->expects($this->once())
            ->method('respond')
            ->with(
                $awsInvocationId,
                $this->callback(function ($json) {
                    $data = json_decode($json, true);
                    return $data['statusCode'] === 200 && isset($data['headers']['Content-Type']);
                })
            );

        $this->httpWorker->respond($awsInvocationId, $responseMock);
    }
}
