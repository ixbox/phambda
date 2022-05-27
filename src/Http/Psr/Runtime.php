<?php

declare(strict_types=1);

namespace Phambda\Http\Psr;

use Phambda\RuntimeInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly HttpWorker $worker,
    ) {
    }

    public function run(): void
    {
        do {
            $request = $this->worker->nextRequest();
            $response = $this->handler->handle($request);

            $this->worker->respond(
                $request,
                $response,
            );
        } while (true);
    }
}
