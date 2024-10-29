<?php

declare(strict_types=1);

namespace Phambda;

use Phambda\Exception\RuntimeException;
use Phambda\Factory\WorkerFactory;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly HandlerInterface $handler,
        private ?Worker $worker = null,
    ) {
        $this->worker ??= WorkerFactory::create();
    }

    public function run(): void
    {
        while (true) {
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
                $this->worker->error($invocation->context->awsRequestId, $error);
            }
        }
    }

    public static function execute(HandlerInterface $handler): void
    {
        (new self($handler))->run();
    }
}
