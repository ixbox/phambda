<?php

declare(strict_types=1);

namespace Phambda;

/**
 * Implement on a handler to clean up when Lambda shuts the execution environment down.
 *
 * Lambda sends SIGTERM to the runtime before it shuts the execution environment down,
 * but only for functions with a registered extension, and it kills the process about
 * 2 seconds later (500 ms with only internal extensions). Runtime and Http\Runtime then
 * call shutdown() and exit.
 *
 * This needs the pcntl extension, and a client for the Runtime API that returns to PHP
 * while it waits for the next invocation, such as Phambda\RuntimeApi\CurlClient.
 * With a client that blocks (php-http/curl-client with its default options, for
 * example), the SIGTERM is handled only after the wait ends, which does not happen
 * before the process is killed.
 */
interface ShutdownInterface
{
    public function shutdown(): void;
}
