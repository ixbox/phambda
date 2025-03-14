<?php

namespace Phambda;

use Throwable;

interface WorkerInterface
{
    public function nextInvocation(): Invocation;
    public function respond(string $invocationId, string $payload): void;
    public function error(string $invocationId, Throwable $error): void;
}
