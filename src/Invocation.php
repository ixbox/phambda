<?php

declare(strict_types=1);

namespace Phambda;

class Invocation
{
    public function __construct(
        public readonly array $event,
        public readonly array $context
    ) {
    }
}
