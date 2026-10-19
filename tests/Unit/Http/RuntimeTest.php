<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Phambda\Context;
use Phambda\Exception\InitializationException;
use Phambda\Exception\RuntimeException;
use Phambda\Http\EventFormat;
use Phambda\Http\Invocation;
use Phambda\Http\Worker;
use Phambda\Http\Runtime;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

class RuntimeTest extends TestCase
{
    public function testHandlerExceptionIsSentThroughTheWorker(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $invocation = new Invocation(
            $request,
            EventFormat::V2,
            new Context(
                functionName: 'testFunction',
                functionVersion: '1',
                invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
                memoryLimitInMb: '128',
                awsRequestId: 'req-1',
                logGroupName: '/aws/lambda/testFunction',
                logStreamName: '2025/02/28/[$LATEST]abcdef',
                deadlineMs: '1234567890',
            ),
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new \RuntimeException('boom'));

        $calls = 0;
        $worker = $this->createMock(Worker::class);
        $worker->method('nextRequest')->willReturnCallback(function () use (&$calls, $invocation) {
            if ($calls++ === 0) {
                return $invocation;
            }

            throw new InitializationException('stop');
        });
        $worker->expects($this->once())
            ->method('respondWithError')
            ->with($invocation, $this->callback(function (\Throwable $error): bool {
                return $error instanceof RuntimeException
                    && $error->getMessage() === 'boom'
                    && $error->getInvocationId() === 'req-1';
            }));

        $runtime = new Runtime($handler, new NullLogger(), $worker);

        $this->expectException(InitializationException::class);
        $runtime->run();
    }
}
