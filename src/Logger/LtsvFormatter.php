<?php

namespace Phambda\Logger;

class LtsvFormatter implements LogFormatterInterface
{
    public function format(array $data): string
    {
        return implode("\t", array_map(
            fn($k, $v) => sprintf("%s:%s", $k, is_array($v) ? json_encode($v) : $v),
            array_keys($data),
            array_values($data)
        ));
    }
}
