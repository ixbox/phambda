<?php

namespace Phambda\Factory;

use Http\Factory\Discovery\HttpClient;
use Http\Factory\Discovery\HttpFactory;
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

class HttpWorkerFactory
{
    public static function create(
        ClientInterface $client = null,
        ServerRequestFactoryInterface $serverRequestFactory = null,
        RequestFactoryInterface $requestFactory = null,
        StreamFactoryInterface $streamFactory = null,
    ): HttpWorkerInterface {
        $client ??= HttpClient::client();
        $serverRequestFactory ??= HttpFactory::serverRequestFactory();
        $requestFactory ??= HttpFactory::requestFactory();
        $streamFactory ??= HttpFactory::streamFactory();

        $worker = new Worker($client, $requestFactory, $streamFactory);

        return new HttpWorker($worker, $serverRequestFactory, $streamFactory);
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
