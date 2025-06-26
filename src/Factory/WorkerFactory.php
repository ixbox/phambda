<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Worker;
use Phambda\WorkerConfiguration;
use Phambda\WorkerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WorkerFactory
{
    public static function create(
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        LoggerInterface $logger = new NullLogger(),
    ): WorkerInterface {
        $logger->info('Creating Worker');

        $configuration = WorkerConfiguration::fromEnvironment($logger);
        $psr18client = new Psr18Client();

        $client ??= $psr18client;
        $requestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;

        return new Worker($client, $requestFactory, $streamFactory, $configuration);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function createFromContainer(ContainerInterface $container): Worker
    {
        $logger = $container->get(LoggerInterface::class);

        return new Worker(
            $container->get(ClientInterface::class),
            $container->get(RequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            WorkerConfiguration::fromEnvironment($logger),
        );
    }
}
