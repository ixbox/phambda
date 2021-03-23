<?php

declare(strict_types=1);

namespace Phambda\Http\Psr;

use Psr\Http\Message\ServerRequestInterface;

/**
 * @property-read string $awsInvocationId
 * @property-read ServerRequestInterface $request
 */
class Invocation
{
    public function __construct(
        private string $awsInvocationId,
        private ServerRequestInterface $request
    ) {
    }

    public function __get(string $name): mixed
    {
        return $this->$name;
    }
}
