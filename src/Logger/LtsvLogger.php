<?php

namespace Phambda\Logger;

class LtsvLogger extends AbstractLogger
{
    public function __construct(string $minimumLevel = 'debug', array $defaultContext = [])
    {
        parent::__construct(new LtsvFormatter(), $minimumLevel, $defaultContext);
    }
}
