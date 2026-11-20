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
 * This needs the pcntl extension, and Phambda\RuntimeApi\CurlClient as the client for
 * the Runtime API. With another PSR-18 client, the SIGTERM is handled only after the
 * wait for the next invocation ends, which does not happen before the process is killed.
 */
interface ShutdownInterface
{
    public function shutdown(): void;
}
