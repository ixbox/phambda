<?php

declare(strict_types=1);

namespace Phambda;

use ArrayAccess;
use Error;
use JsonSerializable;

/**
 * @implements ArrayAccess<string, string|array<string, mixed>|null>
 */
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
        /** Lambda-Runtime-Invocation-Id: identifies this invocation attempt. Empty when the Runtime API does not send it. */
        public string $invocationId = '',
        /** Lambda-Runtime-Trace-Id: the X-Ray tracing header of this invocation. Empty when tracing data is not sent. */
        public string $traceId = '',
        /**
         * Lambda-Runtime-Client-Context, decoded: the client application and device, when
         * invoked through the AWS Mobile SDK. Null when not sent.
         *
         * @var array<string, mixed>|null
         */
        public ?array $clientContext = null,
        /**
         * Lambda-Runtime-Cognito-Identity, decoded: the Amazon Cognito identity
         * ("cognitoIdentityId", "cognitoIdentityPoolId"), when invoked through the AWS Mobile SDK.
         * Null when not sent.
         *
         * @var array<string, mixed>|null
         */
        public ?array $cognitoIdentity = null,
    ) {
    }

    public function offsetExists(mixed $offset): bool
    {
        return match ($offset) {
            'functionName', 'functionVersion', 'invokedFunctionArn', 'memoryLimitInMb', 'awsRequestId', 'logGroupName', 'logStreamName', 'deadlineMs', 'invocationId', 'traceId', 'clientContext', 'cognitoIdentity' => true,
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
            'invocationId' => $this->invocationId,
            'traceId' => $this->traceId,
            'clientContext' => $this->clientContext,
            'cognitoIdentity' => $this->cognitoIdentity,
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

    /**
     * @return array<string, string|array<string, mixed>|null>
     */
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
            'invocationId' => $this->invocationId,
            'traceId' => $this->traceId,
            'clientContext' => $this->clientContext,
            'cognitoIdentity' => $this->cognitoIdentity,
        ];
    }
}
