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
        // queryStringParameters joins duplicates with commas, so rawQueryString is the source of truth.
        // Duplicate keys follow PHP's $_GET semantics (last value wins).
        $this->assertSame('parameter1=value1&parameter1=value2&parameter2=value', $request->getUri()->getQuery());
        $this->assertSame([
            'parameter1' => 'value2',
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
        $this->assertSame('parameter1=value1&parameter1=value2&parameter2=value', $request->getUri()->getQuery());
        $this->assertSame([
            'parameter1' => 'value2',
            'parameter2' => 'value',
        ], $request->getQueryParams());
    }

    public function testV2QueryIsParsedFromRawQueryString(): void
    {
        $payload = $this->load('lambda-function-url.json');
        // rawQueryString only contains a-z, A-Z, 0-9, ".", "_", "-", "%", "&", "=" and "+" (brackets arrive percent-encoded)
        $payload['rawQueryString'] = 'tags%5B%5D=a&tags%5B%5D=b&filter%5Bstatus%5D=open&q=x%2Cy&q=z&name=hello+world';
        // What AWS puts in queryStringParameters for the query above (duplicates joined with commas)
        $payload['queryStringParameters'] = [
            'tags[]' => 'a,b',
            'filter[status]' => 'open',
            'q' => 'x,y,z',
            'name' => 'hello world',
        ];

        $request = $this->transformer->transform(new Event($payload), $this->context(), EventFormat::V2);

        $this->assertSame([
            'tags' => ['a', 'b'],
            'filter' => ['status' => 'open'],
            'q' => 'z',
            'name' => 'hello world',
        ], $request->getQueryParams());
        $this->assertSame($payload['rawQueryString'], $request->getUri()->getQuery());
    }

    public function testV2RawQueryStringWithLeadingQuestionMark(): void
    {
        $payload = $this->load('lambda-function-url.json');
        $payload['rawQueryString'] = '?parameter1=value1&parameter2=value2';

        $request = $this->transformer->transform(new Event($payload), $this->context(), EventFormat::V2);

        $this->assertSame(['parameter1' => 'value1', 'parameter2' => 'value2'], $request->getQueryParams());
        $this->assertSame('parameter1=value1&parameter2=value2', $request->getUri()->getQuery());
    }

    public function testV2FallsBackToQueryStringParametersWithoutRawQueryString(): void
    {
        $payload = $this->load('lambda-function-url.json');
        unset($payload['rawQueryString']);

        $request = $this->transformer->transform(new Event($payload), $this->context(), EventFormat::V2);

        $this->assertSame([
            'parameter1' => 'value1,value2',
            'parameter2' => 'value',
        ], $request->getQueryParams());
        $this->assertSame('parameter1=value1%2Cvalue2&parameter2=value', $request->getUri()->getQuery());
    }

    public function testV1PrefersMultiValueFields(): void
    {
        $request = $this->transform('api-gateway-rest-payload-1.0.json');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/my/path', $request->getUri()->getPath());
        $this->assertSame(['value1', 'value2'], $request->getHeader('header2'));
        $this->assertSame([], $request->getCookieParams());
        // Rebuilt from multiValueQueryStringParameters, then parsed with $_GET semantics (last value wins)
        $this->assertSame(
            'parameter1=value1&parameter2=value1&parameter2=value2&parameter3=value1%2Cvalue2',
            $request->getUri()->getQuery(),
        );
        $this->assertSame([
            'parameter1' => 'value1',
            'parameter2' => 'value2',
            'parameter3' => 'value1,value2',
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

        $this->assertSame('myKey=val1&myKey=val2', $request->getUri()->getQuery());
        $this->assertSame(['myKey' => 'val2'], $request->getQueryParams());
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

        $this->assertSame(['q' => 'a/b'], $multi->getQueryParams());
        $this->assertSame('q=hello%20world&q=a%2Fb', $multi->getUri()->getQuery());
    }

    /**
     * The same URL must produce the same query params regardless of the event format.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function sameQueryInEveryFormat(): iterable
    {
        // ?page=2&tags[]=a&tags[]=b&q=hello+world
        yield 'HTTP API / function URL (2.0)' => [[
            'version' => '2.0',
            'rawPath' => '/',
            'rawQueryString' => 'page=2&tags%5B%5D=a&tags%5B%5D=b&q=hello+world',
            'requestContext' => ['http' => ['method' => 'GET', 'path' => '/']],
        ]];
        yield 'REST API (1.0)' => [[
            'httpMethod' => 'GET',
            'path' => '/',
            'queryStringParameters' => ['page' => '2', 'tags[]' => 'b', 'q' => 'hello world'],
            'multiValueQueryStringParameters' => ['page' => ['2'], 'tags[]' => ['a', 'b'], 'q' => ['hello world']],
        ]];
        yield 'ALB multi-value headers' => [[
            'requestContext' => ['elb' => ['targetGroupArn' => 'arn']],
            'httpMethod' => 'GET',
            'path' => '/',
            'multiValueHeaders' => [],
            'multiValueQueryStringParameters' => ['page' => ['2'], 'tags%5B%5D' => ['a', 'b'], 'q' => ['hello+world']],
        ]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sameQueryInEveryFormat')]
    public function testQueryParamsAreConsistentAcrossFormats(array $payload): void
    {
        $event = new Event($payload);
        $request = $this->transformer->transform($event, $this->context(), EventFormat::fromEvent($event));

        $this->assertSame([
            'page' => '2',
            'tags' => ['a', 'b'],
            'q' => 'hello world',
        ], $request->getQueryParams());
        // The space is "+" in rawQueryString and "%20" when rebuilt; both decode to the same value.
        $this->assertStringStartsWith('page=2&tags%5B%5D=a&tags%5B%5D=b&q=hello', $request->getUri()->getQuery());
    }

    public function testAlbSingleValueKeepsOnlyOneValuePerKey(): void
    {
        // ALB without multi-value headers delivers only the last value of a duplicated key.
        $request = $this->transformer->transform(new Event([
            'requestContext' => ['elb' => ['targetGroupArn' => 'arn']],
            'httpMethod' => 'GET',
            'path' => '/',
            'headers' => [],
            'queryStringParameters' => ['page' => '2', 'tags%5B%5D' => 'b'],
        ]), $this->context(), EventFormat::Alb);

        $this->assertSame(['page' => '2', 'tags' => ['b']], $request->getQueryParams());
        $this->assertSame('page=2&tags%5B%5D=b', $request->getUri()->getQuery());
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
