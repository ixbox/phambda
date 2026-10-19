<?php

declare(strict_types=1);

namespace Phambda\Logger;

/**
 * Formats log data as LTSV (Labeled Tab Separated Values).
 *
 * LTSV format: field1:value1\tfield2:value2\t...
 */
class LtsvFormatter extends AbstractFormatter
{
    /**
     * Format data as LTSV (Labeled Tab Separated Values).
     *
     * @param array<string, mixed> $data Data to format
     * @return string Formatted LTSV string
     */
    protected function doFormat(array $data): string
    {
        return implode("\t", array_map(
            fn ($k, $v) => sprintf('%s:%s', $k, $this->stringify($v)),
            array_keys($data),
            array_values($data)
        ));
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return $json === false ? '' : $json;
    }
}
