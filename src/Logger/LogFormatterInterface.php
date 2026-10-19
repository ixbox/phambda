<?php

namespace Phambda\Logger;

interface LogFormatterInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function format(array $data): string;
}
