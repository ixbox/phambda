<?php

namespace Phambda\Factory;

use Http\Discovery\Psr18Client;
use Phambda\Http\HttpWorker;
use Phambda\Http\HttpWorkerInterface;
use Phambda\Http\RequestTransformer;
use Phambda\Http\RequestTransformerInterface;
use Phambda\Http\ResponseTransformer;
use Phambda\Http\ResponseTransformerInterface;
use Phambda\WorkerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpWorkerFactory
{
    /**
     * Create a new HttpWorker instance with default dependencies.
     *
     * @param LoggerInterface $logger
     * @param WorkerInterface|null $worker If provided, a default worker will not be created
     * @param ServerRequestFactoryInterface|null $serverRequestFactory
     * @param StreamFactoryInterface|null $streamFactory
     * @param RequestTransformerInterface|null $requestTransformer
     * @param ResponseTransformerInterface|null $responseTransformer
     * @return HttpWorkerInterface
     */
    public static function create(
        LoggerInterface $logger = new NullLogger(),
        ?WorkerInterface $worker = null,
        ?ServerRequestFactoryInterface $serverRequestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RequestTransformerInterface $requestTransformer = null,
        ?ResponseTransformerInterface $responseTransformer = null,
    ): HttpWorkerInterface {
        $psr18client = new Psr18Client();

        $serverRequestFactory ??= $psr18client;
        $streamFactory ??= $psr18client;

        // Create worker
        $worker ??= WorkerFactory::create(
            $logger,
            $psr18client,
            $psr18client,
            $streamFactory
        );

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
