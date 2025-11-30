<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

class RequestTransformer implements RequestTransformerInterface
{
    public function __construct(
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
        //
    }

    /**
     * {@inheritdoc}
     *
     * @param Event $event The Lambda event
     * @param Context $context The Lambda context
     * @return ServerRequestInterface The transformed server request
     * @throws TransformationException If the event cannot be transformed
     */
    public function transform(Event $event, Context $context): ServerRequestInterface
    {

        try {
            $method = isset($event['requestContext']) ? $event['requestContext']['http']['method'] : $event['httpMethod'];
            $path = isset($event['requestContext']) ? $event['requestContext']['http']['path'] : $event['path'];

            $request = $this->requestFactory->createServerRequest($method, $path, (array) $context);
            $request = $request
                ->withAttribute('awsRequestId', $context->awsRequestId)
                ->withAttribute('lambda-context', $context);

            // Add headers
            foreach ((array) ($event['headers'] ?? []) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            // Add cookies and query parameters
            $request = $request->withCookieParams((array) ($event['cookies'] ?? []));
            $request = $request->withQueryParams((array) ($event['queryStringParameters'] ?? []));

            // Add body if present
            if (!empty($event['body'])) {
                $request = $request->withBody($this->streamFactory->createStream($event['body']));
            }

            return $request;
        } catch (\Throwable $e) {
            throw TransformationException::forRequest(
                'Failed to transform event to request: ' . $e->getMessage(),
                [
                    'event' => json_encode($event),
                    'context' => [
                        'awsRequestId' => $context->awsRequestId,
                        'functionName' => $context->functionName,
                    ],
                ],
                0,
                $e
            );
        }
    }
}
