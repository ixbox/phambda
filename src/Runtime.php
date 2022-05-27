<?php

declare(strict_types=1);

namespace Phambda;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly HandlerInterface $handler,
        private readonly Worker $worker,
    ) {
    }

    public function run(): void
    {
        while (true) {
            $invocation = $this->worker->nextInvocation();
            $result = $this->handler->handle(
                $invocation->event->toArray(),
                $invocation->context->toArray()
            );
            $this->worker->respond(
                $invocation->context->awsRequestId,
                $result,
            );
        }
    }
}
