<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Context;
use Psr\Http\Message\ServerRequestInterface;

readonly class Invocation
{
    public function __construct(
        public ServerRequestInterface $request,
        public EventFormat $format,
        public Context $context,
    ) {
    }
}
