<?php

namespace Phambda\Http\Psr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Phambda\RuntimeInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Runtime implements RuntimeInterface
{
    public function __construct(
        private RequestHandlerInterface $handler
    ) {
    }

    public function run(): void
    {
        $client = new Client([
            'base_uri' => 'http://' . getenv('AWS_LAMBDA_RUNTIME_API') . '/2018-06-01/',
        ]);
        $worker = new Worker($client);

        while ($invocation = $worker->nextInvocation()) {
            $worker->respond(
                awsInvocationId: $invocation->awsInvocationId,
                response: $this->handler->handle($invocation->request),
            );
        }
    }
}
