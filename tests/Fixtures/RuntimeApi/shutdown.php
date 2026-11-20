<?php

// Runs a Runtime with CurlClient against the server given as the first argument.
// Prints "shutdown" when shutdown() is called.

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Phambda\Context;
use Phambda\Event;
use Phambda\HandlerInterface;
use Phambda\Runtime;
use Phambda\RuntimeApi\CurlClient;
use Phambda\ShutdownInterface;
use Phambda\Worker;
use Phambda\WorkerConfiguration;
use Psr\Log\NullLogger;

$psr17 = new Psr17Factory();
$worker = new Worker(
    new CurlClient($psr17, $psr17),
    $psr17,
    $psr17,
    new WorkerConfiguration("http://{$argv[1]}/2018-06-01", new NullLogger()),
);

$handler = new class () implements HandlerInterface, ShutdownInterface {
    public function handle(Event $event, Context $context): string
    {
        return '"ok"';
    }

    public function shutdown(): void
    {
        echo "shutdown\n";
    }
};

fwrite(STDERR, "ready\n");
(new Runtime($handler, worker: $worker))->run();
