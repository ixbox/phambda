<?php

declare(strict_types=1);

namespace Phambda\Logger;

use RuntimeException;

/**
 * Formats log data as JSON for CloudWatch Logs.
 */
class JsonFormatter extends AbstractFormatter
{
    /**
     * @var int JSON encode options for formatting
     */
    private int $jsonOptions;

    /**
     * @param LoggerConfiguration|null $config Logger configuration
     */
    public function __construct(?LoggerConfiguration $config = null)
    {
        parent::__construct($config);
        $this->jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($config?->isPrettyPrintEnabled()) {
            $this->jsonOptions |= JSON_PRETTY_PRINT;
        }
    }

    /**
     * Format log data as JSON.
     *
     * @param array<string, mixed> $data Log data
     * @return string Formatted JSON string
     * @throws RuntimeException if JSON encoding fails
     */
    protected function doFormat(array $data): string
    {
        try {
            return json_encode($data, $this->jsonOptions | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(
                'Failed to encode log data as JSON: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
