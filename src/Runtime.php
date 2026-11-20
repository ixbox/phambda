<?php

declare(strict_types=1);

namespace Phambda;

use Phambda\Exception\InitializationException;
use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Factory\WorkerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Runtime
{
    private readonly Worker $worker;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HandlerInterface $handler,
        ?LoggerInterface $logger = null,
        ?Worker $worker = null,
        private readonly ?ProcessLimits $limits = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->worker = $worker ?? WorkerFactory::create(
            logger: $this->logger,
        );
        $this->logger->info('Runtime initialized');
    }

    public function run(): void
    {
        $this->logger->info('Starting Lambda invocation loop');
        $limits = $this->resolveLimits();
        $this->boot();
        ShutdownSignal::register($this->handler, $this->logger);

        // The runtime keeps polling the Runtime API until the process is terminated,
        // or until a process limit is reached.
        $handled = 0;
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

                // The handler failed, so report it as a function error with the original exception's type
                $this->worker->error($invocation->context->awsRequestId, $runtimeError, ErrorType::ofFunction($error));
            }

            $reason = $limits->reached(++$handled);
            if ($reason !== null) {
                $this->logger->info('Process limit reached. Stopping so that the bootstrap starts a new process.', [
                    'reason' => $reason,
                ]);

                return;
            }
        }
    }

    /**
     * Read the process limits, reporting invalid settings as an initialization error.
     */
    private function resolveLimits(): ProcessLimits
    {
        try {
            return $this->limits ?? ProcessLimits::fromEnvironment();
        } catch (\InvalidArgumentException $error) {
            $this->logger->critical('Invalid process limits', ['error' => $error->getMessage()]);
            $this->worker->initError($error);

            throw InitializationException::fromEnvironment('Invalid process limits: ' . $error->getMessage(), 0, $error);
        }
    }

    /**
     * Call boot() on the handler once, during the initialization phase.
     */
    private function boot(): void
    {
        if (!$this->handler instanceof BootableInterface) {
            return;
        }

        try {
            $this->handler->boot();
        } catch (\Throwable $error) {
            $this->logger->critical('Handler boot failed', [
                'error' => $error->getMessage(),
                'type' => $error::class,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => explode("\n", $error->getTraceAsString()),
            ]);
            $this->worker->initError($error, ErrorType::ofFunction($error));

            throw InitializationException::fromEnvironment('Handler boot failed: ' . $error->getMessage(), 0, $error);
        }
    }

    public static function execute(HandlerInterface $handler, ?LoggerInterface $logger = null): void
    {
        (new self($handler, $logger))->run();
    }
}
