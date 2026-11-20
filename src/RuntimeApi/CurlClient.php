<?php

declare(strict_types=1);

namespace Phambda\RuntimeApi;

use Http\Client\Curl\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * php-http/curl-client, set up so that signals are handled while it waits.
 *
 * Use it as the client for the Runtime API when the handler implements
 * Phambda\ShutdownInterface. The runtime spends most of its time waiting for
 * /runtime/invocation/next, and PHP runs a signal handler only between PHP
 * statements. With a progress callback, libcurl calls back into PHP about once
 * a second while it waits, so a SIGTERM is handled within about a second
 * instead of after the wait ends (which, at shutdown, it never does).
 *
 * This only adds CURLOPT_NOPROGRESS and CURLOPT_XFERINFOFUNCTION. Passing the
 * same options to php-http/curl-client yourself works just as well.
 */
final class CurlClient implements ClientInterface
{
    private readonly ClientInterface $client;

    /**
     * @param array<int, mixed> $options Other cURL options for php-http/curl-client
     */
    public function __construct(
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $options = [],
    ) {
        if (!class_exists(Client::class)) {
            throw new \LogicException('Phambda\RuntimeApi\CurlClient requires php-http/curl-client');
        }

        $this->client = new Client($responseFactory, $streamFactory, [
            // libcurl calls this about once a second, even while nothing is transferred.
            // Returning to PHP lets pending signal handlers (pcntl_async_signals) run.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn (): int => 0,
        ] + $options);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }
}
