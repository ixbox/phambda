<?php

namespace Phambda\Logger;

class LtsvLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $defaultContext
     */
    public function __construct(string $minimumLevel = 'debug', array $defaultContext = [])
    {
        parent::__construct(new LtsvFormatter(), $minimumLevel, $defaultContext);
    }
}
