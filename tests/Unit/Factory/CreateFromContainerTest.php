<?php

declare(strict_types=1);

namespace Tests\Unit\Factory;

use Nyholm\Psr7\Factory\Psr17Factory;
use Phambda\Factory\WorkerFactory;
use Phambda\Worker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CreateFromContainerTest extends TestCase
{
    /**
     * @param array<string, mixed> $entries
     */
    private function container(array $entries): ContainerInterface
    {
        return new class ($entries) implements ContainerInterface {
            /** @param array<string, mixed> $entries */
            public function __construct(private array $entries)
            {
            }

            public function get(string $id): mixed
            {
                return $this->entries[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->entries);
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function entries(): array
    {
        $psr17 = new Psr17Factory();

        return [
            LoggerInterface::class => new NullLogger(),
            ClientInterface::class => $this->createStub(ClientInterface::class),
            RequestFactoryInterface::class => $psr17,
            StreamFactoryInterface::class => $psr17,
        ];
    }

    public function testCreatesWorkerFromContainer(): void
    {
        $this->assertInstanceOf(Worker::class, WorkerFactory::createFromContainer($this->container($this->entries())));
    }

    public function testRejectsEntryOfUnexpectedType(): void
    {
        $entries = $this->entries();
        $entries[ClientInterface::class] = 'not a client';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Container entry "Psr\Http\Client\ClientInterface" must be an instance of');

        WorkerFactory::createFromContainer($this->container($entries));
    }
}
