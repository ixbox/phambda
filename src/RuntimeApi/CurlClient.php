<?php

declare(strict_types=1);

namespace Phambda\RuntimeApi;

use Http\Discovery\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client for the Lambda Runtime API that uses ext-curl directly.
 *
 * Use it instead of another PSR-18 client when the handler implements
 * Phambda\ShutdownInterface. The runtime spends most of its time waiting for
 * /runtime/invocation/next, and PHP runs a signal handler only between PHP
 * statements. This client lets libcurl call back into PHP about once a second
 * while it waits, so a SIGTERM is handled within about a second instead of
 * after the wait ends (which, at shutdown, it never does).
 *
 * It is written for the Runtime API only: plain HTTP to a local endpoint, no
 * redirects, no timeouts (the request for the next invocation waits as long
 * as it takes).
 */
final class CurlClient implements ClientInterface
{
    private ?\CurlHandle $handle = null;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory = new Psr17Factory(),
        private readonly StreamFactoryInterface $streamFactory = new Psr17Factory(),
    ) {
        if (!extension_loaded('curl')) {
            throw new \LogicException('Phambda\RuntimeApi\CurlClient requires the curl extension');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();
        if ($url === '' || $method === '') {
            throw new NetworkException($request, 'The request has no URL or method');
        }

        $handle = $this->handle ??= curl_init();
        // Reuse the handle, and with it the connection, but none of the previous options
        curl_reset($handle);

        $headerLines = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = "{$name}: {$value}";
            }
        }
        // Send the body right away instead of waiting for "100 Continue"
        $headerLines[] = 'Expect:';

        $status = 0;
        $reason = '';
        /** @var list<array{string, string}> $responseHeaders */
        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$reason, &$responseHeaders): int {
                $trimmed = rtrim($line, "\r\n");
                if (preg_match('#^HTTP/\S+\s+(\d{3})(?:\s+(.*))?$#', $trimmed, $matches) === 1) {
                    // A new status line (for example after "100 Continue") starts a new set of headers
                    $status = (int) $matches[1];
                    $reason = $matches[2] ?? '';
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[] = [trim($name), trim($value)];
                }

                return strlen($line);
            },
            // libcurl calls this about once a second, even while nothing is transferred.
            // Returning to PHP lets pending signal handlers (pcntl_async_signals) run.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn (): int => 0,
        ];

        $body = (string) $request->getBody();
        if ($body !== '' || $method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);
        $content = curl_exec($handle);
        if (!is_string($content)) {
            throw new NetworkException($request, curl_error($handle), curl_errno($handle));
        }

        $response = $this->responseFactory->createResponse($status, $reason);
        foreach ($responseHeaders as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }

        $stream = $this->streamFactory->createStream($content);
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $response->withBody($stream);
    }
}
