<?php

declare(strict_types=1);

namespace Phambda\Http\Psr;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

class Worker
{
    public function __construct(
        private ClientInterface $client
    ) {
    }

    /**
     * @return Invocation
     * @throws GuzzleException
     */
    public function nextInvocation(): Invocation
    {
        $response = $this->client->request('get', 'runtime/invocation/next');

        $payload = json_decode((string) $response->getBody(), true);

        $method = $payload['requestContext']['http']['method'];
        $path = $payload['requestContext']['http']['path'];
        $request = new ServerRequest($method, $path, $payload['headers'], $payload['body']);

        return new Invocation(
            $response->getHeaderLine('Lambda-Runtime-Aws-Request-Id'),
            $request
                ->withCookieParams($payload['cookies'])
                ->withQueryParams($payload['queryStringParameters']),
        );
    }

    /**
     * @param string $awsInvocationId
     * @param ResponseInterface $response
     * @throws GuzzleException
     */
    public function respond(string $awsInvocationId, ResponseInterface $response): void
    {
        $this->client->request('post', "runtime/invocation/$awsInvocationId/response", [
            'json' => [
                "statusCode" => $response->getStatusCode(),
                "headers" => $response->getHeaders(),
                "body" => (string) $response->getBody(),
            ],
        ]);
    }
}
