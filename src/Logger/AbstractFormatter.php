<?php

declare(strict_types=1);

namespace Phambda\Logger;

/**
 * Base class for log formatters.
 * 
 * Provides common functionality for configuration management and Lambda context handling.
 */
abstract class AbstractFormatter implements LogFormatterInterface
{
    use LambdaContextTrait;

    public function __construct(
        protected readonly ?LoggerConfiguration $config = null
    ) {}

    /**
     * Format log data with context information.
     *
     * @param array<string, mixed> $data Log data to format
     * @return string Formatted log string
     */
    public function format(array $data): string
    {
        // Add Lambda context information if available
        $this->addLambdaContext($data, $this->config);

        return $this->doFormat($data);
    }

    /**
     * Perform formatter-specific formatting.
     *
     * @param array<string, mixed> $data Log data to format
     * @return string Formatted log string
     */
    abstract protected function doFormat(array $data): string;
}
