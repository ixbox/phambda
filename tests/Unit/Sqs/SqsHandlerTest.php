<?php

declare(strict_types=1);

namespace Tests\Unit\Sqs;

use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Phambda\Sqs\SqsEvent;
use Phambda\Sqs\SqsHandler;
use Phambda\Sqs\SqsRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Events are the samples in https://docs.aws.amazon.com/lambda/latest/dg/with-sqs.html
 */
class SqsHandlerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function load(string $fixture): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../../Fixtures/Sqs/{$fixture}"), true, flags: JSON_THROW_ON_ERROR);
    }

    private function context(): Context
    {
        return new Context('fn', '1', 'arn', '128', 'req-1', 'group', 'stream', '0');
    }

    /**
     * @param list<string> $failingIds
     * @param list<string> $processed
     */
    private function handler(array $failingIds, array &$processed, bool $report, ?AbstractLogger $logger = null): SqsHandler
    {
        return new class ($failingIds, $processed, $report, $logger) extends SqsHandler {
            /**
             * @param list<string> $failingIds
             * @param list<string> $processed
             */
            public function __construct(private array $failingIds, private array &$processed, bool $report, ?AbstractLogger $logger)
            {
                $logger === null ? parent::__construct($report) : parent::__construct($report, $logger);
            }

            protected function handleRecord(SqsRecord $record, Context $context): void
            {
                $this->processed[] = $record->messageId;
                if (in_array($record->messageId, $this->failingIds, true)) {
                    throw new \RuntimeException("cannot process {$record->messageId}");
                }
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function batch(array $records): array
    {
        return ['Records' => $records];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $messageId, string $queue = 'my-queue'): array
    {
        $record = $this->load('standard.json')['Records'][0];
        $record['messageId'] = $messageId;
        $record['eventSourceARN'] = "arn:aws:sqs:us-east-2:123456789012:{$queue}";

        return $record;
    }

    public function testParsesTheStandardQueueSample(): void
    {
        $records = SqsEvent::fromEvent(new Event($this->load('standard.json')))->records;

        $this->assertCount(2, $records);
        $record = $records[0];
        $this->assertSame('059f36b4-87a3-44ab-83d2-661975830a7d', $record->messageId);
        $this->assertSame('Test message.', $record->body);
        $this->assertSame('arn:aws:sqs:us-east-2:123456789012:my-queue', $record->eventSourceArn);
        $this->assertSame('us-east-2', $record->awsRegion);
        $this->assertSame(1, $record->receiveCount());
        $this->assertSame('2018-12-17T21:37:29.183+00:00', $record->sentAt()?->format('Y-m-d\TH:i:s.vP'));
        $this->assertSame('myValue', $record->messageAttribute('myAttribute'));
        $this->assertNull($record->messageAttribute('missing'));
        $this->assertFalse($record->isFifo());
        $this->assertNull($record->messageGroupId());
    }

    public function testParsesTheFifoQueueSample(): void
    {
        $record = SqsEvent::fromEvent(new Event($this->load('fifo.json')))->records[0];

        $this->assertTrue($record->isFifo());
        $this->assertSame('1', $record->messageGroupId());
    }

    public function testJsonBody(): void
    {
        $record = $this->record('m1');
        $record['body'] = '{"orderId":42}';

        $parsed = SqsEvent::fromEvent(new Event($this->batch([$record])))->records[0];

        $this->assertSame(['orderId' => 42], $parsed->json());
        $this->expectException(\JsonException::class);
        SqsEvent::fromEvent(new Event($this->load('standard.json')))->records[0]->json();
    }

    public function testRejectsEventsThatAreNotFromSqs(): void
    {
        $this->expectException(TransformationException::class);
        SqsEvent::fromEvent(new Event(['Records' => [['eventSource' => 'aws:sns', 'messageId' => 'x']]]));
    }

    public function testRejectsEventsWithoutRecords(): void
    {
        $this->expectException(TransformationException::class);
        SqsEvent::fromEvent(new Event(['detail-type' => 'Scheduled Event']));
    }

    public function testSuccessfulBatchReportsNoFailures(): void
    {
        $processed = [];
        $response = $this->handler([], $processed, report: true)
            ->handle(new Event($this->load('standard.json')), $this->context());

        $this->assertSame('{"batchItemFailures":[]}', $response);
        $this->assertCount(2, $processed);
    }

    public function testReportsOnlyTheFailedMessages(): void
    {
        $processed = [];
        $records = [];
        $logger = new class () extends AbstractLogger {
            /** @var list<array<mixed>> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $context['message_id'] ?? null];
            }
        };
        $response = $this->handler(['m2', 'm4'], $processed, true, $logger)->handle(
            new Event($this->batch(array_map($this->record(...), ['m1', 'm2', 'm3', 'm4', 'm5']))),
            $this->context(),
        );

        $this->assertSame(
            ['batchItemFailures' => [['itemIdentifier' => 'm2'], ['itemIdentifier' => 'm4']]],
            json_decode($response, true),
        );
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $processed);
        $this->assertSame([['error', 'm2'], ['error', 'm4']], $logger->records);
    }

    public function testFifoStopsAtTheFirstFailureAndReportsTheRest(): void
    {
        $processed = [];
        $response = $this->handler(['m2'], $processed, report: true)->handle(
            new Event($this->batch(array_map(fn (string $id) => $this->record($id, 'orders.fifo'), ['m1', 'm2', 'm3', 'm4']))),
            $this->context(),
        );

        $this->assertSame(
            ['batchItemFailures' => [['itemIdentifier' => 'm2'], ['itemIdentifier' => 'm3'], ['itemIdentifier' => 'm4']]],
            json_decode($response, true),
        );
        // m3 and m4 are not processed, so their order is kept
        $this->assertSame(['m1', 'm2'], $processed);
    }

    public function testWithoutPartialBatchResponsesAFailureFailsTheWholeBatch(): void
    {
        $processed = [];

        try {
            $this->handler(['m2'], $processed, report: false)->handle(
                new Event($this->batch(array_map($this->record(...), ['m1', 'm2', 'm3']))),
                $this->context(),
            );
            $this->fail('The exception should propagate so the invocation fails');
        } catch (\RuntimeException $e) {
            $this->assertSame('cannot process m2', $e->getMessage());
        }

        $this->assertSame(['m1', 'm2'], $processed);
    }
}
