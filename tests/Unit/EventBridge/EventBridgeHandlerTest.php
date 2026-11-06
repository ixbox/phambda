<?php

declare(strict_types=1);

namespace Tests\Unit\EventBridge;

use Phambda\Context;
use Phambda\Event;
use Phambda\EventBridge\EventBridgeEvent;
use Phambda\EventBridge\EventBridgeHandler;
use Phambda\Exception\TransformationException;
use PHPUnit\Framework\TestCase;

class EventBridgeHandlerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function load(string $fixture): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/../../Fixtures/EventBridge/{$fixture}"), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testParsesAnAwsServiceEvent(): void
    {
        // Sample from https://docs.aws.amazon.com/eventbridge/latest/ref/overiew-event-structure.html
        $event = EventBridgeEvent::fromEvent(new Event($this->load('cloudformation.json')));

        $this->assertSame('0', $event->version);
        $this->assertSame('12345678-1234-1234-1234-111122223333', $event->id);
        $this->assertSame('CloudFormation Stack Status Change', $event->detailType);
        $this->assertSame('aws.cloudformation', $event->source);
        $this->assertSame('123456789012', $event->account);
        $this->assertSame('us-east-1', $event->region);
        $this->assertSame(['arn:aws:cloudformation:us-east-1:123456789012:stack/teststack'], $event->resources);
        $this->assertSame('CREATE_COMPLETE', $event->detail['status-details']['status']);
        // The AWS sample says 2022-04-31, which PHP rolls over to May 1
        $this->assertSame('2022-05-01T17:00:00+00:00', $event->time->format(DATE_ATOM));
    }

    public function testParsesAScheduledEvent(): void
    {
        $event = EventBridgeEvent::fromEvent(new Event($this->load('scheduled.json')));

        $this->assertSame('Scheduled Event', $event->detailType);
        $this->assertSame('aws.events', $event->source);
        $this->assertSame([], $event->detail);
        $this->assertSame('2015-10-08T16:53:06+00:00', $event->time->format(DATE_ATOM));
    }

    public function testRejectsEventsThatAreNotFromEventBridge(): void
    {
        $this->expectException(TransformationException::class);
        EventBridgeEvent::fromEvent(new Event(['Records' => []]));
    }

    public function testRejectsAnInvalidTime(): void
    {
        $payload = $this->load('scheduled.json');
        $payload['time'] = 'not a time';

        $this->expectException(TransformationException::class);
        EventBridgeEvent::fromEvent(new Event($payload));
    }

    public function testHandlerReceivesTheTypedEvent(): void
    {
        $handler = new class () extends EventBridgeHandler {
            public ?EventBridgeEvent $received = null;

            protected function handleEvent(EventBridgeEvent $event, Context $context): void
            {
                $this->received = $event;
            }
        };

        $response = $handler->handle(new Event($this->load('scheduled.json')), new Context('fn', '1', 'arn', '128', 'req-1', 'g', 's', '0'));

        $this->assertSame('null', $response);
        $this->assertSame('53dc4d37-cffa-4f76-80c9-8b7d4a4d2eaa', $handler->received?->id);
    }
}
