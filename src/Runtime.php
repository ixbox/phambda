<?php

declare(strict_types=1);

namespace Phambda;

use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Factory\WorkerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Runtime implements RuntimeInterface
{
    private readonly WorkerInterface $worker;

    public function __construct(
        private readonly HandlerInterface $handler,
        ?LoggerInterface $logger = null,
        ?WorkerInterface $worker = null,
    ) {
        $this->worker = $worker ?? WorkerFactory::create(
            logger: $logger,
        );
        $this->worker->logger->info('Runtime initialized');
    }

    public function run(): void
    {
        $this->worker->logger->info('Starting Lambda invocation loop');
        while (true) {
            try {
                $this->worker->logger->debug('Processing new invocation');
                $invocation = $this->worker->nextInvocation();
            } catch (\Phambda\Exception\InitializationException $error) {
                $this->worker->logger->critical('Fatal error detected. Terminating process.', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'context' => $error->getContext(),
                ]);
                exit(1);
            }

            try {
                $result = $this->handler->handle(
                    $invocation->event,
                    $invocation->context,
                );
                $this->worker->respond(
                    $invocation->context->awsRequestId,
                    $result,
                );
            } catch (PhambdaException $error) {
                $this->worker->logger->error('Error occurred while processing invocation', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $invocation->context->awsRequestId,
                    'context' => $error instanceof PhambdaException ? $error->getContext() : [],
                ]);
                $this->worker->error($invocation->context->awsRequestId, $error);
            } catch (\Throwable $error) {
                $this->worker->logger->error('Unexpected error in handler execution', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $invocation->context->awsRequestId,
                    'file' => $error->getFile(),
                    'line' => $error->getLine(),
                ]);

                // Wrap non-Phambda exceptions with context
                $runtimeError = RuntimeException::forInvocation(
                    $error->getMessage(),
                    $invocation->context->awsRequestId,
                    $error->getCode(),
                    $error
                );

                $this->worker->error($invocation->context->awsRequestId, $runtimeError);
            }
        }
    }

    public static function execute(HandlerInterface $handler, LoggerInterface $logger = new NullLogger()): void
    {
        (new self($handler, $logger))->run();
    }
}
