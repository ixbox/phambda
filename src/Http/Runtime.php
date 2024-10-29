<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Factory\HttpWorkerFactory;
use Phambda\RuntimeInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private ?HttpWorkerInterface $worker = null,
    ) {
        $this->worker ??= HttpWorkerFactory::create();
    }

    public function run(): void
    {
        do {
            $request = $this->worker->nextRequest();
            $response = $this->handler->handle($request);
            $this->worker->respond(
                $request->getAttribute('awsRequestId'),
                $response,
            );
        } while (true);
    }

    public static function execute(RequestHandlerInterface $handler): void
    {
        (new self($handler))->run();
    }
}
