<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Http\HttpWorker;
use Phambda\Http\HttpWorkerInterface;
use Phambda\Http\RequestTransformer;
use Phambda\Http\RequestTransformerInterface;
use Phambda\Http\ResponseTransformer;
use Phambda\Http\ResponseTransformerInterface;
use Phambda\Worker;
use Phambda\WorkerConfiguration;
use Phambda\WorkerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpWorkerFactory
{
    /**
     * Create a new HttpWorker instance with default dependencies.
     *
     * @param ClientInterface|null $client
     * @param ServerRequestFactoryInterface|null $serverRequestFactory
     * @param RequestFactoryInterface|null $requestFactory
     * @param StreamFactoryInterface|null $streamFactory
     * @param LoggerInterface|null $logger
     * @param RequestTransformerInterface|null $requestTransformer
     * @param ResponseTransformerInterface|null $responseTransformer
     * @return HttpWorkerInterface
     */
    public static function create(
        ?ClientInterface $client = null,
        ?ServerRequestFactoryInterface $serverRequestFactory = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RequestTransformerInterface $requestTransformer = null,
        ?ResponseTransformerInterface $responseTransformer = null,
        LoggerInterface $logger = new NullLogger(),
    ): HttpWorkerInterface {
        $psr18client = new Psr18Client();

        $client ??= $psr18client;
        $serverRequestFactory ??= $psr18client;
        $requestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;

        $logger->info('Creating Worker');
        $worker = new Worker($client, $requestFactory, $streamFactory, WorkerConfiguration::fromEnvironment($logger));

        // Create transformers if not provided
        $requestTransformer ??= new RequestTransformer($serverRequestFactory, $streamFactory);
        $responseTransformer ??= new ResponseTransformer();

        $logger->info('Creating HttpWorker');
        return new HttpWorker(
            $worker,
            $serverRequestFactory,
            $streamFactory,
            $requestTransformer,
            $responseTransformer,
            $logger
        );
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function createFromContainer(ContainerInterface $container): HttpWorkerInterface
    {
        $logger = $container->get(LoggerInterface::class);

        return new HttpWorker(
            $container->get(WorkerInterface::class),
            $container->get(ServerRequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class),
            $container->get(RequestTransformerInterface::class),
            $container->get(ResponseTransformerInterface::class),
            $logger,
        );
    }
}
