<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\InitializationException;
use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Factory\HttpWorkerFactory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Runtime
{
    private readonly Worker $worker;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        ?LoggerInterface $logger = null,
        ?Worker $worker = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->worker = $worker ?? HttpWorkerFactory::create(logger: $this->logger);
        $this->logger->info('HTTP runtime initialized');
    }

    public function run(): void
    {
        $this->logger->info('Starting HTTP request handling loop');

        // The runtime keeps polling the Runtime API until the process is terminated.
        while (true) { // @phpstan-ignore while.alwaysTrue
            $invocation = null;
            try {
                $invocation = $this->worker->nextRequest();
                $response = $this->handler->handle($invocation->request);
                $this->worker->respond($invocation, $response);
            } catch (InitializationException $error) {
                $this->logger->critical('Fatal initialization error', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'context' => $error->getContext(),
                ]);
                throw $error;
            } catch (PhambdaException $error) {
                if ($invocation === null) {
                    throw InitializationException::fromEnvironment(
                        'HTTP invocation failed before request id was available: ' . $error->getMessage(),
                        0,
                        $error
                    );
                }

                $this->logger->error('Request handling error', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $invocation->context->awsRequestId,
                    'context' => $error->getContext(),
                ]);

                $this->worker->respondWithError($invocation, $error);
            } catch (\Throwable $error) {
                if ($invocation === null) {
                    throw InitializationException::fromEnvironment(
                        'Unexpected HTTP runtime error before request id was available: ' . $error->getMessage(),
                        0,
                        $error
                    );
                }

                $this->logger->error('Unexpected runtime error', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $invocation->context->awsRequestId,
                    'file' => $error->getFile(),
                    'line' => $error->getLine(),
                    'trace' => explode("\n", $error->getTraceAsString()),
                ]);

                $runtimeError = RuntimeException::forInvocation(
                    $error->getMessage(),
                    $invocation->context->awsRequestId,
                    $error->getCode(),
                    $error
                );

                $this->worker->respondWithError($invocation, $runtimeError);
            }
        }
    }

    public static function execute(RequestHandlerInterface $handler, ?LoggerInterface $logger = null): void
    {
        (new self($handler, $logger))->run();
    }
}
