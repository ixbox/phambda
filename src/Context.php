<?php

declare(strict_types=1);

namespace Phambda;

use ArrayAccess;
use Error;
use JsonSerializable;

readonly class Context implements JsonSerializable, ArrayAccess
{
    public function __construct(
        public string $functionName,
        public string $functionVersion,
        public string $invokedFunctionArn,
        public string $memoryLimitInMb,
        public string $awsRequestId,
        public string $logGroupName,
        public string $logStreamName,
        public string $deadlineMs,
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return match ($offset) {
            'functionName', 'functionVersion', 'invokedFunctionArn', 'memoryLimitInMb', 'awsRequestId', 'logGroupName', 'logStreamName', 'deadlineMs' => true,
            default => false,
        };
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'functionName' => $this->functionName,
            'functionVersion' => $this->functionVersion,
            'invokedFunctionArn' => $this->invokedFunctionArn,
            'memoryLimitInMb' => $this->memoryLimitInMb,
            'awsRequestId' => $this->awsRequestId,
            'logGroupName' => $this->logGroupName,
            'logStreamName' => $this->logStreamName,
            'deadlineMs' => $this->deadlineMs,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new Error("Cannot modify readonly property");
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new Error("Cannot modify readonly property");
    }

    public function jsonSerialize(): array
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
        ];
    }
}
