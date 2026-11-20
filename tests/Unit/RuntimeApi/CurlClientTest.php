<?php

declare(strict_types=1);

namespace Tests\Unit\RuntimeApi;

use Nyholm\Psr7\Factory\Psr17Factory;
use Phambda\RuntimeApi\CurlClient;
use Phambda\RuntimeApi\NetworkException;
use PHPUnit\Framework\TestCase;

class CurlClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $address = '';

    public static function setUpBeforeClass(): void
    {
        self::$address = '127.0.0.1:' . self::freePort();
        $process = proc_open(
            [PHP_BINARY, '-S', self::$address, __DIR__ . '/../../Fixtures/RuntimeApi/server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            // The request for /next blocks one worker, so serve requests in parallel
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        self::assertIsResource($process);
        self::$server = $process;
        self::waitForPort(self::$address);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server, SIGKILL);
            proc_close(self::$server);
        }
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public static function waitForPort(string $address): void
    {
        for ($i = 0; $i < 100; $i++) {
            $connection = @stream_socket_client("tcp://{$address}", $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        self::fail("Server at {$address} did not start");
    }

    private function client(): CurlClient
    {
        $psr17 = new Psr17Factory();

        return new CurlClient($psr17, $psr17);
    }

    public function testSendsTheRequestAndReadsTheResponse(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('POST', 'http://' . self::$address . '/echo')
            ->withHeader('X-Test', 'value')
            ->withBody($psr17->createStream('{"hello":"world"}'));

        $response = $this->client()->sendRequest($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Created', $response->getReasonPhrase());
        $this->assertSame(['a', 'b'], $response->getHeader('X-Multi'));
        $this->assertSame(
            ['method' => 'POST', 'body' => '{"hello":"world"}', 'header' => 'value'],
            json_decode($response->getBody()->getContents(), true),
        );
    }

    public function testReusesTheHandleWithoutCarryingOverOptions(): void
    {
        $psr17 = new Psr17Factory();
        $client = $this->client();
        $client->sendRequest($psr17->createRequest('POST', 'http://' . self::$address . '/echo')
            ->withHeader('X-Test', 'first')
            ->withBody($psr17->createStream('body')));

        $response = $client->sendRequest($psr17->createRequest('GET', 'http://' . self::$address . '/echo'));

        $this->assertSame(
            ['method' => 'GET', 'body' => '', 'header' => null],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testFindsPsr17FactoriesWhenNoneAreGiven(): void
    {
        $request = (new Psr17Factory())->createRequest('GET', 'http://' . self::$address . '/missing');

        $this->assertSame(404, (new CurlClient())->sendRequest($request)->getStatusCode());
    }

    public function testReturnsErrorStatusesAsResponses(): void
    {
        $psr17 = new Psr17Factory();

        $response = $this->client()->sendRequest($psr17->createRequest('GET', 'http://' . self::$address . '/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not found', (string) $response->getBody());
    }

    public function testThrowsANetworkExceptionWhenTheServerIsUnreachable(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('GET', 'http://127.0.0.1:' . self::freePort() . '/');

        try {
            $this->client()->sendRequest($request);
            $this->fail('sendRequest() should fail');
        } catch (NetworkException $e) {
            $this->assertSame($request, $e->getRequest());
        }
    }

    public function testShutdownIsCalledOnSigtermWhileWaitingForTheNextInvocation(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Needs the pcntl and posix extensions');
        }

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Fixtures/RuntimeApi/shutdown.php', self::$address],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            // Wait until the runtime is blocked on /next
            $this->assertSame("ready\n", fgets($pipes[2]));
            usleep(500_000);

            $sentAt = microtime(true);
            posix_kill(proc_get_status($process)['pid'], SIGTERM);

            while (proc_get_status($process)['running'] && microtime(true) - $sentAt < 5) {
                usleep(50_000);
            }
            $elapsed = microtime(true) - $sentAt;

            $this->assertFalse(proc_get_status($process)['running'], 'The runtime did not exit after SIGTERM');
            // Lambda kills the process 2 seconds after SIGTERM
            $this->assertLessThan(1.5, $elapsed);
            $this->assertSame("shutdown\n", stream_get_contents($pipes[1]));
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }
}
