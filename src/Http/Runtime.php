<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Exception\InitializationException;
use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Factory\HttpWorkerFactory;
use Phambda\RuntimeInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Runtime implements RuntimeInterface
{
    private readonly Worker $worker;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Worker $worker = null,
    ) {
        $this->worker = $worker ?? HttpWorkerFactory::create(logger: $logger);
        $this->logger->info('HTTP runtime initialized');
    }

    public function run(): void
    {
        $this->logger->info('Starting HTTP request handling loop');

        while (true) {
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

    public static function execute(RequestHandlerInterface $handler, LoggerInterface $logger = new NullLogger()): void
    {
        (new self($handler, $logger))->run();
    }
}
