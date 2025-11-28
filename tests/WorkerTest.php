<?php

namespace Phambda;

use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class WorkerTest extends TestCase
{
    public function testNextInvocation(): void
    {
        $dummyEventJson = '{"dummy": "event"}';

        // Create a dummy body stream
        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('getContents')->willReturn($dummyEventJson);

        // Create a dummy response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($bodyStream);
        $response->method('getHeaderLine')
            ->willReturnMap([
                ['Lambda-Runtime-Invoked-Function-Arn', 'dummyArn'],
                ['Lambda-Runtime-Aws-Request-Id', 'dummyRequestId'],
                ['Lambda-Runtime-Deadline-Ms', '123456789'],
            ]);

        // Create a dummy request
        $dummyRequest = $this->createMock(RequestInterface::class);

        // Create mocks for factories and client
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($dummyRequest);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($bodyStream);

        // Instantiate the Worker under test
        $worker = new Worker($client, $requestFactory, $streamFactory);

        // Call nextInvocation and assert its structure.
        $invocation = $worker->nextInvocation();

        // Check that $invocation has 'event' and 'context' using property_exists instead of assertObjectHasAttribute
        $this->assertTrue(property_exists($invocation, 'event'));
        $this->assertTrue(property_exists($invocation, 'context'));

        // Assert context header values
        $this->assertEquals('dummyArn', $invocation->context->invokedFunctionArn);
        $this->assertEquals('dummyRequestId', $invocation->context->awsRequestId);
        $this->assertEquals('123456789', $invocation->context->deadlineMs);
    }

    public function testRespondSuccess(): void
    {
        $invocationId = '123';
        $payload = 'success payload';

        $dummyResponse = $this->createMock(ResponseInterface::class);
        $dummyResponse->method('getStatusCode')->willReturn(202);

        $dummyRequest = $this->createMock(RequestInterface::class);
        // Configure chain: when withBody is called, return the dummy request itself.
        $dummyRequest->method('withBody')->willReturn($dummyRequest);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($dummyResponse);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($dummyRequest);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $worker = new Worker($client, $requestFactory, $streamFactory);

        $worker->respond($invocationId, $payload);

        $this->assertTrue(true); // If no exception is thrown, the test passes
    }

    public function testError(): void
    {
        $invocationId = '123';
        $errorMessage = 'error message';

        $dummyResponse = $this->createMock(ResponseInterface::class);
        $dummyResponse->method('getStatusCode')->willReturn(202);

        $dummyRequest = $this->createMock(RequestInterface::class);
        $dummyRequest->method('withBody')->willReturn($dummyRequest);
        $dummyRequest->method('withHeader')->willReturn($dummyRequest);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($dummyResponse);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($dummyRequest);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $worker = new Worker($client, $requestFactory, $streamFactory);

        // Pass a Throwable instead of a string.
        $worker->error($invocationId, new Exception($errorMessage));

        $this->assertTrue(true); // If no exception is thrown, the test passes
    }

    public function testInitError(): void
    {
        $errorMessage = 'init error message';

        $dummyResponse = $this->createMock(ResponseInterface::class);
        $dummyResponse->method('getStatusCode')->willReturn(202);

        $dummyRequest = $this->createMock(RequestInterface::class);
        $dummyRequest->method('withBody')->willReturn($dummyRequest);
        $dummyRequest->method('withHeader')
            ->willReturn($dummyRequest);

        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($dummyResponse);

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($dummyRequest);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));

        $worker = new Worker($client, $requestFactory, $streamFactory);

        // Use reflection to call private method initError
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('initError');

        // Pass a Throwable instead of a string.
        $method->invoke($worker, new Exception($errorMessage));

        $this->assertTrue(true); // If no exception is thrown, the test passes
    }
}
