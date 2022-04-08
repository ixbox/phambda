<?php

declare(strict_types=1);

namespace Phambda;

class Payload
{
    public function __construct(
        public string $awsInvocationId,
        public string $body,
    ) {
    }
}
