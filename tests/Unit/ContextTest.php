<?php

declare(strict_types=1);

namespace Tests\Unit;

use Phambda\Context;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->assertSame('testFunction', $context->functionName);
        $this->assertSame('1.0', $context->functionVersion);
        $this->assertSame('arn:aws:lambda:region:account-id:function:testFunction', $context->invokedFunctionArn);
        $this->assertSame('128', $context->memoryLimitInMb);
        $this->assertSame('testRequestId', $context->awsRequestId);
        $this->assertSame('/aws/lambda/testFunction', $context->logGroupName);
        $this->assertSame('2025/02/28/[$LATEST]abcdef1234567890', $context->logStreamName);
        $this->assertSame('1234567890', $context->deadlineMs);
    }

    public function testJsonSerializeReturnsValidData(): void
    {
        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $expected = [
            'functionName' => 'testFunction',
            'functionVersion' => '1.0',
            'invokedFunctionArn' => 'arn:aws:lambda:region:account-id:function:testFunction',
            'memoryLimitInMB' => '128',
            'awsRequestId' => 'testRequestId',
            'logGroupName' => '/aws/lambda/testFunction',
            'logStreamName' => '2025/02/28/[$LATEST]abcdef1234567890',
            'deadlineMs' => '1234567890',
        ];

        $this->assertSame($expected, $context->jsonSerialize());
    }

    public function testArrayAccessImplementation(): void
    {
        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $this->assertTrue(isset($context['functionName']));
        $this->assertSame('testFunction', $context['functionName']);
        $this->assertFalse(isset($context['nonExistentProperty']));
    }

    public function testReadonlyPropertiesCannotBeModified(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $context = new Context(
            functionName: 'testFunction',
            functionVersion: '1.0',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef1234567890',
            deadlineMs: '1234567890'
        );

        $context['functionName'] = 'newFunctionName';
    }
}
