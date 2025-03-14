<?php

declare(strict_types=1);

namespace Phambda\Logger;

/**
 * Provides Lambda context handling capabilities for formatters.
 */
trait LambdaContextTrait
{
    /**
     * Add Lambda context information to log data.
     *
     * @param array<string, mixed> &$data Log data to augment
     * @param LoggerConfiguration|null $config Logger configuration
     */
    private function addLambdaContext(array &$data, ?LoggerConfiguration $config = null): void
    {
        $flags = $config?->getContextFlags() ?? [
            'lambda_context' => true,
            'request_id' => true,
            'memory_usage' => false,
        ];

        if ($flags['lambda_context']) {
            // Add standard Lambda environment information
            $lambdaContext = [];
            $envVars = [
                'AWS_LAMBDA_FUNCTION_NAME',
                'AWS_LAMBDA_FUNCTION_VERSION',
                'AWS_LAMBDA_LOG_GROUP_NAME',
                'AWS_LAMBDA_LOG_STREAM_NAME',
            ];

            foreach ($envVars as $var) {
                $value = getenv($var);
                if ($value !== false) {
                    $lambdaContext[$var] = $value;
                }
            }

            if (!empty($lambdaContext)) {
                $data['lambda_context'] = $lambdaContext;
            }
        }

        if ($flags['request_id']) {
            // Add request ID if available
            $awsRequestId = getenv('_X_AMZN_TRACE_ID');
            if ($awsRequestId !== false) {
                $data['request_id'] = $awsRequestId;
            }
        }

        if ($flags['memory_usage'] || ($config?->isMemoryUsageEnabled() ?? false)) {
            // Add current memory usage
            $data['memory'] = [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
            ];
        }
    }
}
