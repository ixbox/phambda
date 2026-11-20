<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\BootableInterface;
use Phambda\ErrorType;
use Phambda\Exception\InitializationException;
use Phambda\Exception\PhambdaException;
use Phambda\Exception\RuntimeException;
use Phambda\Factory\HttpWorkerFactory;
use Phambda\ProcessLimits;
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
        private readonly ?ProcessLimits $limits = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->worker = $worker ?? HttpWorkerFactory::create(logger: $this->logger);
        $this->logger->info('HTTP runtime initialized');
    }

    public function run(): void
    {
        $this->logger->info('Starting HTTP request handling loop');
        $limits = $this->resolveLimits();
        $this->boot();

        // The runtime keeps polling the Runtime API until the process is terminated,
        // or until a process limit is reached.
        $handled = 0;
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

    public static function execute(RequestHandlerInterface $handler, ?LoggerInterface $logger = null): void
    {
        (new self($handler, $logger))->run();
    }
}
