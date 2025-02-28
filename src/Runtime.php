<?php

declare(strict_types=1);

namespace Phambda;

use Phambda\Exception\RuntimeException;
use Phambda\Factory\WorkerFactory;
use Psr\Log\LoggerInterface;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly HandlerInterface $handler,
        private ?LoggerInterface $logger = null,
        private ?Worker $worker = null,
    ) {
        $this->logger?->info('Creating Runtime instance');
        $this->worker ??= WorkerFactory::create(logger: $logger);
    }

    public function run(): void
    {
        $this->logger?->info('Starting Lambda invocation loop');
        while (true) {
            $this->logger?->debug('Processing new invocation');
            $invocation = $this->worker->nextInvocation();
            try {
                $result = $this->handler->handle(
                    $invocation->event,
                    $invocation->context,
                );
                $this->worker->respond(
                    $invocation->context->awsRequestId,
                    $result,
                );
            } catch (RuntimeException $error) {
                $this->logger?->error('Error occurred while processing invocation: ' . $error->getMessage());
                $this->worker->error($invocation->context->awsRequestId, $error);
            }
        }
    }

    public static function execute(HandlerInterface $handler): void
    {
        (new self($handler))->run();
    }
}
