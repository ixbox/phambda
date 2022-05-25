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
            $result = $this->handler->handle($invocation->event, $invocation->context);
            $payload = new Payload(
                $invocation->context['aws_request_id'],
                $result,
            );
            $this->worker->respond($payload);
        }
    }
}