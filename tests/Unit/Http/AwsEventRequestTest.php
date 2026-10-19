<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Phambda\Http\EventFormat;
use Phambda\Http\RequestTransformer;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Events are the samples published by AWS, with non-JSON placeholders replaced.
 *
 * @see https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api-develop-integrations-lambda.html
 * @see https://docs.aws.amazon.com/lambda/latest/dg/urls-invocation.html
 * @see https://docs.aws.amazon.com/apigateway/latest/developerguide/set-up-lambda-proxy-integrations.html
 * @see https://docs.aws.amazon.com/elasticloadbalancing/latest/application/lambda-functions.html
 */
class AwsEventRequestTest extends TestCase
{
    private RequestTransformer $transformer;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->transformer = new RequestTransformer($factory, $factory);
    }

    public function testV2Sample(): void
    {
        $request = $this->transform('api-gateway-http-payload-2.0.json');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/my/path', $request->getUri()->getPath());
        $this->assertSame('value1', $request->getHeaderLine('header1'));
        $this->assertSame('value1,value2', $request->getHeaderLine('header2'));
        $this->assertSame(['cookie1' => '', 'cookie2' => ''], $request->getCookieParams());
        $this->assertSame([
            'parameter1' => 'value1,value2',
            'parameter2' => 'value',
        ], $request->getQueryParams());
        $this->assertSame('Hello from Lambda', (string) $request->getBody());
        $this->assertSame(['parameter1' => 'value1'], $request->getAttribute('pathParameters'));
        $this->assertSame([
            'stageVariable1' => 'value1',
            'stageVariable2' => 'value2',
        ], $request->getAttribute('stageVariables'));
    }

    public function testV2DecodesBase64Body(): void
    {
        $request = $this->transform('api-gateway-http-payload-2.0-base64.json');

        $this->assertSame('Hello from Lambda', (string) $request->getBody());
    }

    public function testFunctionUrlUsesV2(): void
    {
        $request = $this->transform('lambda-function-url.json');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/my/path', $request->getUri()->getPath());
        $this->assertSame('Hello from client!', (string) $request->getBody());
        $this->assertSame(['cookie1' => '', 'cookie2' => ''], $request->getCookieParams());
    }

    public function testV1PrefersMultiValueFields(): void
    {
        $request = $this->transform('api-gateway-rest-payload-1.0.json');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/my/path', $request->getUri()->getPath());
        $this->assertSame(['value1', 'value2'], $request->getHeader('header2'));
        $this->assertSame([], $request->getCookieParams());
        $this->assertSame([
            'parameter1' => ['value1'],
            'parameter2' => ['value1', 'value2'],
            'parameter3' => ['value1,value2'],
        ], $request->getQueryParams());
        $this->assertSame('Hello from Lambda!', (string) $request->getBody());
        $this->assertNull($request->getAttribute('pathParameters'));
    }

    public function testV1ReadsCookieHeader(): void
    {
        $payload = $this->load('api-gateway-rest-payload-1.0.json');
        $payload['headers']['cookie'] = 'session=abc123; theme=dark';
        $payload['multiValueHeaders']['cookie'] = ['session=abc123; theme=dark'];

        $request = $this->transformer->transform(new Event($payload), $this->context(), EventFormat::V1);

        $this->assertSame([
            'session' => 'abc123',
            'theme' => 'dark',
        ], $request->getCookieParams());
    }

    public function testAlbSample(): void
    {
        $request = $this->transform('alb.json');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getUri()->getPath());
        $this->assertSame(['myKey' => 'val2'], $request->getQueryParams());
        $this->assertSame(['name2' => 'value2'], $request->getCookieParams());
        $this->assertSame('name2=value2', $request->getHeaderLine('cookie'));
        $this->assertSame('request_body', (string) $request->getBody());
    }

    public function testAlbMultiValueSample(): void
    {
        $request = $this->transform('alb-multi-value.json');

        $this->assertSame(['val1', 'val2'], $request->getQueryParams()['myKey']);
        $this->assertSame([
            'name1' => 'value1',
            'name2' => 'value2',
        ], $request->getCookieParams());
        $this->assertSame(['name1=value1', 'name2=value2'], $request->getHeader('cookie'));
    }

    public function testRawPathIsUsedWhenV2HttpPathIsMissing(): void
    {
        $request = $this->transformer->transform(new Event([
            'version' => '2.0',
            'rawPath' => '/fallback',
            'requestContext' => [
                'http' => [
                    'method' => 'GET',
                ],
            ],
        ]), $this->context(), EventFormat::V2);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/fallback', $request->getUri()->getPath());
    }

    public function testInvalidBase64BodyThrows(): void
    {
        $this->expectException(TransformationException::class);

        $this->transformer->transform(new Event([
            'version' => '2.0',
            'requestContext' => [
                'http' => [
                    'method' => 'POST',
                    'path' => '/',
                ],
            ],
            'body' => '%%%',
            'isBase64Encoded' => true,
        ]), $this->context(), EventFormat::V2);
    }

    public function testAlbDecodesTheQueryString(): void
    {
        $request = $this->transformer->transform(new Event([
            'requestContext' => ['elb' => ['targetGroupArn' => 'arn']],
            'httpMethod' => 'GET',
            'path' => '/',
            'queryStringParameters' => [
                'q' => 'hello+world',
                'encoded' => 'hello%20world',
            ],
            'headers' => [],
        ]), $this->context(), EventFormat::Alb);

        $this->assertSame([
            'q' => 'hello world',
            'encoded' => 'hello world',
        ], $request->getQueryParams());

        $multi = $this->transformer->transform(new Event([
            'requestContext' => ['elb' => ['targetGroupArn' => 'arn']],
            'httpMethod' => 'GET',
            'path' => '/',
            'multiValueQueryStringParameters' => ['q' => ['hello+world', 'a%2Fb']],
        ]), $this->context(), EventFormat::AlbMultiValue);

        $this->assertSame(['hello world', 'a/b'], $multi->getQueryParams()['q']);
    }

    public function testNullMultiValueHeadersIsSingleValueAlb(): void
    {
        $format = EventFormat::fromEvent(new Event([
            'requestContext' => ['elb' => ['targetGroupArn' => 'arn']],
            'httpMethod' => 'GET',
            'path' => '/',
            'headers' => ['host' => 'example.com'],
            'multiValueHeaders' => null,
        ]));

        $this->assertSame(EventFormat::Alb, $format);
    }

    public function testUnrecognizedEventThrows(): void
    {
        $this->expectException(TransformationException::class);

        EventFormat::fromEvent(new Event([
            'foo' => 'bar',
        ]));
    }

    private function transform(string $fixture): ServerRequestInterface
    {
        $event = new Event($this->load($fixture));

        return $this->transformer->transform($event, $this->context(), EventFormat::fromEvent($event));
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $fixture): array
    {
        $json = file_get_contents(__DIR__ . '/../../Fixtures/Http/' . $fixture);
        $this->assertIsString($json);
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function context(): Context
    {
        return new Context(
            functionName: 'testFunction',
            functionVersion: '1',
            invokedFunctionArn: 'arn:aws:lambda:region:account-id:function:testFunction',
            memoryLimitInMb: '128',
            awsRequestId: 'testRequestId',
            logGroupName: '/aws/lambda/testFunction',
            logStreamName: '2025/02/28/[$LATEST]abcdef',
            deadlineMs: '1234567890',
        );
    }
}
