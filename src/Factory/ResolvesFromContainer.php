<?php

declare(strict_types=1);

namespace Phambda\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use UnexpectedValueException;

/**
 * @internal
 */
trait ResolvesFromContainer
{
    /**
     * Get a service from the container and check that it has the expected type.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException If the service is not an instance of $id
     */
    private static function resolve(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new UnexpectedValueException(sprintf(
                'Container entry "%s" must be an instance of %s, %s given',
                $id,
                $id,
                get_debug_type($service),
            ));
        }

        return $service;
    }
}
