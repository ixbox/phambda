<?php

declare(strict_types=1);

namespace Phambda;

class Invocation
{
    public function __construct(
        public readonly Event $event,
        public readonly Context $context
    ) {
    }
}
