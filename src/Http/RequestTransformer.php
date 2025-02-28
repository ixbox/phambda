<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Context;
use Phambda\Event;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class RequestTransformer
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function transform(Event $event, Context $context): ServerRequestInterface
    {
        $method = isset($event['requestContext']) ? $event['requestContext']['http']['method'] : $event['httpMethod'];
        $path = isset($event['requestContext']) ? $event['requestContext']['http']['path'] : $event['path'];

        $request = $this->requestFactory->createServerRequest($method, $path, (array) $context);
        $request = $request->withAttribute('awsRequestId', $context['awsRequestId']);

        foreach ((array) $event['headers'] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request->withCookieParams((array) $event['cookies']);
        $request = $request->withQueryParams((array) $event['queryStringParameters']);

        if ($event['body']) {
            $request = $request->withBody($this->streamFactory->createStream($event['body']));
        }

        return $request;
    }
}
