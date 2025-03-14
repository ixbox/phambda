<?php

declare(strict_types=1);

namespace Phambda;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WorkerConfiguration
{
    public function __construct(
        public readonly string $baseUri,
        public readonly LoggerInterface $logger,
    ) {
        //
    }

    public static function fromEnvironment(?LoggerInterface $logger = null): self
    {
        $awsLambdaRuntimeApi = getenv('AWS_LAMBDA_RUNTIME_API') ?: '127.0.0.1:9001';
        return new self(
            baseUri: "http://{$awsLambdaRuntimeApi}/2018-06-01",
            logger: $logger ?? new NullLogger()
        );
    }
}
