<?php

declare(strict_types=1);

namespace Phambda;

use Phambda\Exception\InitializationException;
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
        private readonly LoggerInterface $logger = new NullLogger(),
        ?WorkerInterface $worker = null,
    ) {
        $this->worker = $worker ?? WorkerFactory::create(
            logger: $logger,
        );
        $this->logger->info('Runtime initialized');
    }

    public function run(): void
    {
        $this->logger->info('Starting Lambda invocation loop');
        while (true) {
            $invocation = null;
            try {
                $this->logger->debug('Processing new invocation');
                $invocation = $this->worker->nextInvocation();
                $result = $this->handler->handle(
                    $invocation->event,
                    $invocation->context,
                );
                $this->worker->respond(
                    $invocation->context->awsRequestId,
                    $result,
                );
            } catch (InitializationException $error) {
                $this->logger->critical('Fatal error detected. Terminating process.', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'context' => $error->getContext(),
                ]);
                throw $error;
            } catch (PhambdaException $error) {
                if ($invocation === null) {
                    throw InitializationException::fromEnvironment(
                        'Invocation failed before context was available: ' . $error->getMessage(),
                        0,
                        $error
                    );
                }

                $this->logger->error('Error occurred while processing invocation', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                    'request_id' => $invocation->context->awsRequestId,
                    'context' => $error->getContext(),
                ]);
                $this->worker->error($invocation->context->awsRequestId, $error);
            } catch (\Throwable $error) {
                if ($invocation === null) {
                    throw InitializationException::fromEnvironment(
                        'Unexpected runtime error before invocation was available: ' . $error->getMessage(),
                        0,
                        $error
                    );
                }

                $this->logger->error('Unexpected error in handler execution', [
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
