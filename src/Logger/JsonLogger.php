<?php

namespace Phambda\Logger;

class JsonLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $defaultContext
     */
    public function __construct(string $minimumLevel = 'debug', array $defaultContext = [])
    {
        parent::__construct(new JsonFormatter(), $minimumLevel, $defaultContext);
    }
}
