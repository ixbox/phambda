<?php

namespace Phambda;

interface WorkerInterface
{
    public function nextInvocation(): Invocation;
    public function respond(string $invocationId, string $payload): void;
}
