<?php

declare(strict_types=1);

namespace Phambda;

class Context
{
    public function __construct(
        public readonly $functionName,
        public readonly $functionVersion,
        public readonly $invokedFunctionArn,
        public readonly $memoryLimitInMb,
        public readonly $awsRequestId,
        public readonly $logGroupName,
        public readonly $logStreamName,
        public readonly $deadlineMs,
        public readonly $traceId,
        public readonly $xAmznTraceId,
        public readonly $identity,
        public readonly $clientContext,
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
