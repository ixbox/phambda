<?php

declare(strict_types=1);

namespace Phambda\Http\Psr;

use Psr\Http\Server\RequestHandlerInterface;

class Runtime
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private HttpWorker $worker,
    ) {
    }

    public function run(): void
    {
        do {
            $request = $this->worker->nextRequest();
            $response = $this->handler->handle($request);
            $this->worker->respond(
                awsInvocationId: $request->getServerParams()['aws_request_id'],
                response: $response,
            );
        } while (true);
    }
}
