<?php

declare(strict_types=1);

namespace Phambda;

/**
 * Implement on a handler to run initialization once per execution environment.
 *
 * Runtime and Http\Runtime call boot() during the Lambda initialization phase,
 * before the first invocation is requested. If boot() throws, the error is
 * reported to /runtime/init/error and the process exits, so Lambda records it
 * as an initialization error.
 */
interface BootableInterface
{
    public function boot(): void;
}
