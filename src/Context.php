<?php

declare(strict_types=1);

namespace Phambda;

class Context
{
    public function __construct(
        public readonly string $functionName,
        public readonly string $functionVersion,
        public readonly string $invokedFunctionArn,
        public readonly string $memoryLimitInMb,
        public readonly string $awsRequestId,
        public readonly string $logGroupName,
        public readonly string $logStreamName,
        public readonly string $deadlineMs,
        public readonly string $traceId,
        public readonly string $xAmznTraceId,
        public readonly string $identity,
        public readonly string $clientContext,
    ) {
    }

    public function toArray(): array
    {
        return [
            'functionName' => $this->functionName,
            'functionVersion' => $this->functionVersion,
            'invokedFunctionArn' => $this->invokedFunctionArn,
            'memoryLimitInMB' => $this->memoryLimitInMb,
            'awsRequestId' => $this->awsRequestId,
            'logGroupName' => $this->logGroupName,
            'logStreamName' => $this->logStreamName,
            'deadlineMs' => $this->deadlineMs,
            'traceId' => $this->traceId,
            'xAmznTraceId' => $this->xAmznTraceId,
            'identity' => $this->identity,
            'clientContext' => $this->clientContext,
        ];
    }
}
