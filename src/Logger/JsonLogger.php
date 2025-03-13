<?php

namespace Phambda\Logger;

use Stringable;

class JsonLogger extends AbstractLogger
{
    public function __construct(string $minimumLevel = 'debug', array $defaultContext = [])
    {
        parent::__construct(new JsonFormatter(), $minimumLevel, $defaultContext);
    }
}
