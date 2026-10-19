<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Phambda\Exception\InitializationException;
use Phambda\Exception\RuntimeException;
use Phambda\Http\HttpWorkerInterface;
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
        $request->method('getAttribute')->with('awsRequestId')->willReturn('req-1');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new \RuntimeException('boom'));

        $calls = 0;
        $worker = $this->createMock(HttpWorkerInterface::class);
        $worker->method('nextRequest')->willReturnCallback(function () use (&$calls, $request) {
            if ($calls++ === 0) {
                return $request;
            }

            throw new InitializationException('stop');
        });
        $worker->expects($this->once())
            ->method('respondWithError')
            ->with('req-1', $this->callback(function (\Throwable $error): bool {
                return $error instanceof RuntimeException
                    && $error->getMessage() === 'boom'
                    && $error->getInvocationId() === 'req-1';
            }));

        $runtime = new Runtime($handler, new NullLogger(), $worker);

        $this->expectException(InitializationException::class);
        $runtime->run();
    }
}
