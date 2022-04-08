<?php

namespace Phambda;

interface WorkerInterface
{
    public function nextInvocation(): Invocation;
    public function respond(Payload $payload): void;
}
