<?php

namespace Phambda\Logger;

interface LogFormatterInterface
{
    public function format(array $data): string;
}
