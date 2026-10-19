<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Worker;
use Phambda\WorkerConfiguration;
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
    use ResolvesFromContainer;

    public static function create(
        LoggerInterface $logger = new NullLogger(),
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): Worker {
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
     * @throws \UnexpectedValueException If a container entry has an unexpected type
     */
    public static function createFromContainer(ContainerInterface $container): Worker
    {
        $logger = self::resolve($container, LoggerInterface::class);

        return new Worker(
            self::resolve($container, ClientInterface::class),
            self::resolve($container, RequestFactoryInterface::class),
            self::resolve($container, StreamFactoryInterface::class),
            WorkerConfiguration::fromEnvironment($logger),
        );
    }
}
