<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Http\HttpWorker;
use Phambda\Http\HttpWorkerInterface;
use Phambda\Worker;
use Phambda\WorkerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

class HttpWorkerFactory
{
    public static function create(
        ?ClientInterface $client = null,
        ?ServerRequestFactoryInterface $serverRequestFactory = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): HttpWorkerInterface {
        $psr18client = new Psr18Client();

        $client ??= $psr18client;
        $serverRequestFactory ??= $psr18client;
        $requestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;

        $worker = new Worker($client, $requestFactory, $streamFactory, null, $logger);

        return new HttpWorker($worker, $serverRequestFactory, $streamFactory, $logger);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function createFromContainer(ContainerInterface $container): Worker
    {
        return new Worker(
            $container->get(WorkerInterface::class),
            $container->get(ServerRequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
        );
    }
}
