<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Http\RequestTransformer;
use Phambda\Http\ResponseTransformer;
use Phambda\Http\Worker;
use Phambda\Worker as EventWorker;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpWorkerFactory
{
    use ResolvesFromContainer;

    /**
     * Create a new HttpWorker instance with default dependencies.
     *
     * @param LoggerInterface $logger
     * @param EventWorker|null $worker If provided, a default worker will not be created
     * @param ServerRequestFactoryInterface|null $serverRequestFactory
     * @param StreamFactoryInterface|null $streamFactory
     * @param RequestTransformer|null $requestTransformer
     * @param ResponseTransformer|null $responseTransformer
     * @param ResponseFactoryInterface|null $responseFactory
     * @return Worker
     */
    public static function create(
        LoggerInterface $logger = new NullLogger(),
        ?EventWorker $worker = null,
        ?ServerRequestFactoryInterface $serverRequestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RequestTransformer $requestTransformer = null,
        ?ResponseTransformer $responseTransformer = null,
        ?ResponseFactoryInterface $responseFactory = null,
    ): Worker {
        $psr18client = new Psr18Client();

        $serverRequestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;
        $responseFactory ??= $psr18client;

        // Create worker
        $worker ??= WorkerFactory::create(
            $logger,
            $psr18client,
            $psr18client,
            $streamFactory
        );

        // Create transformers if not provided
        $requestTransformer ??= new RequestTransformer($serverRequestFactory, $streamFactory);
        $responseTransformer ??= new ResponseTransformer($logger);

        $logger->info('Creating HttpWorker');
        return new Worker(
            $worker,
            $streamFactory,
            $responseFactory,
            $requestTransformer,
            $responseTransformer,
            $logger
        );
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws \UnexpectedValueException If a container entry has an unexpected type
     */
    public static function createFromContainer(ContainerInterface $container): Worker
    {
        return new Worker(
            self::resolve($container, EventWorker::class),
            self::resolve($container, StreamFactoryInterface::class),
            self::resolve($container, ResponseFactoryInterface::class),
            self::resolve($container, RequestTransformer::class),
            self::resolve($container, ResponseTransformer::class),
            self::resolve($container, LoggerInterface::class),
        );
    }
}
