<?php

declare(strict_types=1);

namespace Tests\Unit\S3;

use Phambda\Context;
use Phambda\Event;
use Phambda\Exception\TransformationException;
use Phambda\S3\S3Event;
use Phambda\S3\S3Handler;
use Phambda\S3\S3Record;
use Phambda\Sqs\SqsHandler;
use Phambda\Sqs\SqsRecord;
use PHPUnit\Framework\TestCase;

/**
 * Events are the samples in https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html
 */
class S3HandlerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function load(string $fixture): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../../Fixtures/S3/{$fixture}"), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function withObject(string $eventName, array $object): array
    {
        $event = $this->load('put.json');
        $event['Records'][0]['eventName'] = $eventName;
        $event['Records'][0]['s3']['object'] = $object;

        return $event;
    }

    private function context(): Context
    {
        return new Context('fn', '1', 'arn', '128', 'req-1', 'group', 'stream', '0');
    }

    public function testParsesThePutSample(): void
    {
        $event = S3Event::fromEvent(new Event($this->load('put.json')));

        $this->assertFalse($event->isTestEvent);
        $this->assertCount(1, $event->records);
        $record = $event->records[0];
        $this->assertSame('2.6', $record->eventVersion);
        $this->assertSame('ObjectCreated:Put', $record->eventName);
        $this->assertTrue($record->isObjectCreated());
        $this->assertFalse($record->isObjectRemoved());
        $this->assertSame('1970-01-01T00:00:00.000+00:00', $record->eventTime->format('Y-m-d\TH:i:s.vP'));
        $this->assertSame('us-west-2', $record->awsRegion);
        $this->assertSame('amzn-s3-demo-bucket', $record->bucketName);
        $this->assertSame('arn:aws:s3:::amzn-s3-demo-bucket', $record->bucketArn);
        $this->assertSame('HappyFace.jpg', $record->key);
        $this->assertSame(1024, $record->size);
        $this->assertSame('d41d8cd98f00b204e9800998ecf8427e', $record->eTag);
        $this->assertSame('096fKKXTRTtl3on89fVO.nfljtsv6qko', $record->versionId);
        $this->assertSame('0055AED6DCD90281E5', $record->sequencer);
        $this->assertSame('172.16.0.1', $record->record['requestParameters']['sourceIPAddress']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodedKeys(): iterable
    {
        // The example in the AWS documentation
        yield 'space as +' => ['red+flower.jpg', 'red flower.jpg'];
        yield 'multibyte' => ['%E6%97%A5%E6%9C%AC%E8%AA%9E.txt', '日本語.txt'];
        yield 'literal plus' => ['a%2Bb.txt', 'a+b.txt'];
        yield 'path' => ['images/2026/photo%281%29.png', 'images/2026/photo(1).png'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedKeys')]
    public function testKeyIsUrlDecoded(string $encoded, string $decoded): void
    {
        $record = S3Event::fromEvent(new Event($this->withObject('ObjectCreated:Put', ['key' => $encoded, 'size' => 1])))->records[0];

        $this->assertSame($decoded, $record->key);
        $this->assertSame($encoded, $record->encodedKey);
    }

    public function testDeleteEventWithoutSizeAndETag(): void
    {
        $record = S3Event::fromEvent(new Event($this->withObject('ObjectRemoved:Delete', ['key' => 'old.txt', 'sequencer' => '0055AED6DCD90281E6'])))->records[0];

        $this->assertTrue($record->isObjectRemoved());
        $this->assertNull($record->size);
        $this->assertNull($record->eTag);
        $this->assertNull($record->versionId);
    }

    public function testTestEventHasNoRecordsAndIsIgnoredByTheHandler(): void
    {
        $event = S3Event::fromEvent(new Event($this->load('test-event.json')));
        $this->assertTrue($event->isTestEvent);
        $this->assertSame([], $event->records);

        $handler = new class () extends S3Handler {
            public int $calls = 0;

            protected function handleRecord(S3Record $record, Context $context): void
            {
                $this->calls++;
            }
        };

        $this->assertSame('null', $handler->handle(new Event($this->load('test-event.json')), $this->context()));
        $this->assertSame(0, $handler->calls);
    }

    public function testHandlerReceivesEachRecordAndFailuresPropagate(): void
    {
        $handler = new class () extends S3Handler {
            /** @var list<string> */
            public array $keys = [];

            protected function handleRecord(S3Record $record, Context $context): void
            {
                $this->keys[] = $record->key;
                if ($record->key === 'broken.jpg') {
                    throw new \RuntimeException('cannot read');
                }
            }
        };
        $event = $this->load('put.json');
        $second = $event['Records'][0];
        $second['s3']['object']['key'] = 'broken.jpg';
        $event['Records'][] = $second;

        try {
            $handler->handle(new Event($event), $this->context());
            $this->fail('The exception should propagate so the invocation fails');
        } catch (\RuntimeException $e) {
            $this->assertSame('cannot read', $e->getMessage());
        }

        $this->assertSame(['HappyFace.jpg', 'broken.jpg'], $handler->keys);
    }

    public function testNotificationDeliveredThroughSqs(): void
    {
        $sqsEvent = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/Sqs/standard.json'), true, flags: JSON_THROW_ON_ERROR);
        $sqsEvent['Records'][0]['body'] = (string) file_get_contents(__DIR__ . '/../../Fixtures/S3/put.json');
        $sqsEvent['Records'][1]['body'] = (string) file_get_contents(__DIR__ . '/../../Fixtures/S3/test-event.json');

        $handler = new class () extends SqsHandler {
            /** @var list<string> */
            public array $keys = [];

            protected function handleRecord(SqsRecord $record, Context $context): void
            {
                foreach (S3Event::fromJson($record->body)->records as $s3) {
                    $this->keys[] = "{$s3->bucketName}/{$s3->key}";
                }
            }
        };

        $handler->handle(new Event($sqsEvent), $this->context());

        $this->assertSame(['amzn-s3-demo-bucket/HappyFace.jpg'], $handler->keys);
    }

    public function testRejectsUnsupportedMajorVersion(): void
    {
        $event = $this->load('put.json');
        $event['Records'][0]['eventVersion'] = '3.0';

        $this->expectException(TransformationException::class);
        $this->expectExceptionMessage('Unsupported S3 event version "3.0"');
        S3Event::fromEvent(new Event($event));
    }

    public function testRejectsEventsThatAreNotFromS3(): void
    {
        $this->expectException(TransformationException::class);
        S3Event::fromEvent(new Event(json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/Sqs/standard.json'), true)));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(TransformationException::class);
        S3Event::fromJson('Test message.');
    }
}
