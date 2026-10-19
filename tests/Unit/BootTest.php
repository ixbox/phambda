<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Phambda\BootableInterface;
use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\InitializationException;
use Phambda\Factory\HttpWorkerFactory;
use Phambda\HandlerInterface;
use Phambda\Http\Runtime as HttpRuntime;
use Phambda\Runtime;
use Phambda\Worker;
use Phambda\WorkerConfiguration;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;

/**
 * Plays the Runtime API: returns queued responses and records every call in order.
 */
final class ScriptedRuntimeApi implements ClientInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<ResponseInterface|ClientExceptionInterface> $nextResponses responses for GET /next, in order */
    public function __construct(private array $nextResponses)
    {
    }

    /** @var list<string> Lambda-Runtime-Invocation-Id sent with each call ('' when absent) */
    public array $invocationIdHeaders = [];

    public static function invocation(string $requestId, string $body = '{}', string $invocationId = ''): ResponseInterface
    {
        $headers = ['Lambda-Runtime-Aws-Request-Id' => $requestId];
        if ($invocationId !== '') {
            $headers['Lambda-Runtime-Invocation-Id'] = $invocationId;
        }

        return new Response(200, $headers, $body);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = (string) preg_replace('#^/2018-06-01#', '', $request->getUri()->getPath());
        $this->calls[] = $request->getMethod() . ' ' . $path;
        $this->invocationIdHeaders[] = $request->getHeaderLine('Lambda-Runtime-Invocation-Id');

        if ($path === '/runtime/invocation/next') {
            $next = array_shift($this->nextResponses) ?? new Response(500);
            if ($next instanceof ClientExceptionInterface) {
                throw $next;
            }

            return $next;
        }

        return new Response(202);
    }
}

class BootTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private function worker(ScriptedRuntimeApi $api): Worker
    {
        $psr17 = new Psr17Factory();

        return new Worker($api, $psr17, $psr17, new WorkerConfiguration('http://runtime/2018-06-01', new NullLogger()));
    }

    private function eventHandler(bool $failBoot): HandlerInterface
    {
        $log = &$this->log;

        return new class ($log, $failBoot) implements HandlerInterface, BootableInterface {
            /** @param list<string> $log */
            public function __construct(private array &$log, private bool $failBoot)
            {
            }

            public function boot(): void
            {
                $this->log[] = 'boot';
                if ($this->failBoot) {
                    throw new \RuntimeException('DB unavailable');
                }
            }

            public function handle(Event $event, Context $context): string
            {
                $this->log[] = 'handle ' . $context->awsRequestId;

                return '"ok"';
            }
        };
    }

    public function testBootRunsOnceBeforeTheFirstInvocationIsRequested(): void
    {
        $api = new ScriptedRuntimeApi([ScriptedRuntimeApi::invocation('req-1'), ScriptedRuntimeApi::invocation('req-2')]);
        $runtime = new Runtime($this->eventHandler(failBoot: false), worker: $this->worker($api));

        try {
            $runtime->run();
            $this->fail('The runtime should stop when /next fails');
        } catch (InitializationException) {
        }

        $this->assertSame(['boot', 'handle req-1', 'handle req-2'], $this->log);
        // Failing /next after initialization must not post /runtime/init/error
        $this->assertSame([
            'GET /runtime/invocation/next',
            'POST /runtime/invocation/req-1/response',
            'GET /runtime/invocation/next',
            'POST /runtime/invocation/req-2/response',
            'GET /runtime/invocation/next',
        ], $api->calls);
    }

    public function testBootFailureIsReportedAsInitializationError(): void
    {
        $api = new ScriptedRuntimeApi([ScriptedRuntimeApi::invocation('req-1')]);
        $runtime = new Runtime($this->eventHandler(failBoot: true), worker: $this->worker($api));

        try {
            $runtime->run();
            $this->fail('The runtime should stop when boot() fails');
        } catch (InitializationException $e) {
            $this->assertStringContainsString('DB unavailable', $e->getMessage());
        }

        $this->assertSame(['boot'], $this->log);
        // Reported before any invocation is requested, so Lambda records an initialization error
        $this->assertSame(['POST /runtime/init/error'], $api->calls);
    }

    public function testFirstNextThatNeverReachesLambdaIsReportedAsInitializationError(): void
    {
        $unreachable = new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {
        };
        $api = new ScriptedRuntimeApi([$unreachable]);
        $worker = $this->worker($api);

        try {
            $worker->nextInvocation();
            $this->fail('nextInvocation() should fail');
        } catch (InitializationException) {
        }

        $this->assertSame(['GET /runtime/invocation/next', 'POST /runtime/init/error'], $api->calls);
    }

    public function testErrorResponseFromNextIsNotReportedAsInitializationError(): void
    {
        // /next was received, so the initialization phase is over (500 means "exit promptly")
        $api = new ScriptedRuntimeApi([new Response(500)]);
        $worker = $this->worker($api);

        try {
            $worker->nextInvocation();
            $this->fail('nextInvocation() should fail');
        } catch (InitializationException) {
        }

        $this->assertSame(['GET /runtime/invocation/next'], $api->calls);
    }

    public function testInitErrorIsNotPostedAfterTheFirstInvocationWasRequested(): void
    {
        $api = new ScriptedRuntimeApi([ScriptedRuntimeApi::invocation('req-1')]);
        $worker = $this->worker($api);
        $worker->nextInvocation();

        $worker->initError(new \RuntimeException('too late'));

        $this->assertSame(['GET /runtime/invocation/next'], $api->calls);
    }

    public function testHttpRuntimeBootsBootableHandlers(): void
    {
        $api = new ScriptedRuntimeApi([]);
        $psr17 = new Psr17Factory();
        $httpWorker = HttpWorkerFactory::create(
            worker: $this->worker($api),
            serverRequestFactory: $psr17,
            streamFactory: $psr17,
            responseFactory: $psr17,
        );
        $handler = new class () implements RequestHandlerInterface, BootableInterface {
            public function boot(): void
            {
                throw new \RuntimeException('config missing');
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        try {
            (new HttpRuntime($handler, worker: $httpWorker))->run();
            $this->fail('The runtime should stop when boot() fails');
        } catch (InitializationException $e) {
            $this->assertStringContainsString('config missing', $e->getMessage());
        }

        $this->assertSame(['POST /runtime/init/error'], $api->calls);
    }

    public function testHandlersWithoutBootAreUnaffected(): void
    {
        $api = new ScriptedRuntimeApi([ScriptedRuntimeApi::invocation('req-1')]);
        $handler = new class () implements HandlerInterface {
            public function handle(Event $event, Context $context): string
            {
                return '"ok"';
            }
        };

        try {
            (new Runtime($handler, worker: $this->worker($api)))->run();
        } catch (InitializationException) {
        }

        $this->assertSame([
            'GET /runtime/invocation/next',
            'POST /runtime/invocation/req-1/response',
            'GET /runtime/invocation/next',
        ], $api->calls);
    }

    public function testInvocationIdIsEchoedBackOnResponseAndError(): void
    {
        $api = new ScriptedRuntimeApi([
            ScriptedRuntimeApi::invocation('req-ok', invocationId: 'attempt-1'),
            ScriptedRuntimeApi::invocation('req-fail', '{"fail":true}', invocationId: 'attempt-2'),
            ScriptedRuntimeApi::invocation('req-legacy'),
        ]);
        $handler = new class () implements HandlerInterface {
            public function handle(Event $event, Context $context): string
            {
                if ($event['fail'] ?? false) {
                    throw new \RuntimeException('boom');
                }

                return json_encode($context->invocationId, JSON_THROW_ON_ERROR);
            }
        };

        try {
            (new Runtime($handler, worker: $this->worker($api)))->run();
        } catch (InitializationException) {
        }

        $this->assertSame([
            'GET /runtime/invocation/next',
            'POST /runtime/invocation/req-ok/response',
            'GET /runtime/invocation/next',
            'POST /runtime/invocation/req-fail/error',
            'GET /runtime/invocation/next',
            'POST /runtime/invocation/req-legacy/response',
            'GET /runtime/invocation/next',
        ], $api->calls);
        // Sent back only on /response and /error of the same invocation; omitted when /next had none
        $this->assertSame(['', 'attempt-1', '', 'attempt-2', '', '', ''], $api->invocationIdHeaders);
    }
}
