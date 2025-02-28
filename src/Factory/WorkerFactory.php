<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Worker;
use Phambda\WorkerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

class WorkerFactory
{
    public static function create(
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): WorkerInterface {
        $psr18client = new Psr18Client();

        $client ??= $psr18client;
        $requestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;

        return new Worker($client, $requestFactory, $streamFactory, null, $logger);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function createFromContainer(ContainerInterface $container): Worker
    {
        return new Worker(
            $container->get(ClientInterface::class),
            $container->get(RequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            null,
            $container->get(LoggerInterface::class),
        );
    }
}
