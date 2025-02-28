<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Factory\HttpWorkerFactory;
use Phambda\RuntimeInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private ?LoggerInterface $logger = null,
        private ?HttpWorkerInterface $worker = null,
    ) {
        $logger?->info('Creating HTTP runtime');
        $this->worker ??= HttpWorkerFactory::create(logger: $logger);
    }

    public function run(): void
    {
        $this->logger?->info('Starting HTTP request handling loop');
        do {
            $this->logger?->debug('Processing new HTTP request');
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
