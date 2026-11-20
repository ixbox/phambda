<?php

declare(strict_types=1);

namespace Phambda;

use Psr\Log\LoggerInterface;

/**
 * Calls ShutdownInterface::shutdown() when the process receives SIGTERM.
 *
 * @internal
 */
final class ShutdownSignal
{
    public static function register(object $handler, LoggerInterface $logger): void
    {
        if (!$handler instanceof ShutdownInterface) {
            return;
        }

        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            $logger->warning('The pcntl extension is not available, so shutdown() will not be called');

            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($handler, $logger): never {
            $logger->info('SIGTERM received. Calling shutdown()');
            try {
                $handler->shutdown();
            } catch (\Throwable $error) {
                $logger->error('shutdown() failed', [
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                ]);
            }

            // Calling exit here would not end the process: when the signal arrives while
            // libcurl waits for the next invocation, the handler runs inside a curl callback
            // and libcurl keeps waiting. Let the default action of SIGTERM end it instead.
            $pid = getmypid();
            if (function_exists('posix_kill') && $pid !== false) {
                pcntl_signal(SIGTERM, SIG_DFL);
                posix_kill($pid, SIGTERM);
            }

            exit(0);
        });
    }
}
