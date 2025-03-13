<?php

namespace Phambda\Logger;

class JsonFormatter implements LogFormatterInterface
{
    public function format(array $data): string
    {
        return json_encode($data);
    }
}
