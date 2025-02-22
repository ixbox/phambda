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

class WorkerFactory
{
    public static function create(
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): WorkerInterface {
        $psr18client = new Psr18Client();

        $client ??= $psr18client;
        $requestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;

        return new Worker($client, $requestFactory, $streamFactory);
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
        );
    }
}
