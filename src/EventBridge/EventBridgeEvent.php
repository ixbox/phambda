<?php

declare(strict_types=1);

namespace Phambda\EventBridge;

use DateTimeImmutable;
use Exception;
use Phambda\Event;
use Phambda\Exception\TransformationException;

/**
 * An Amazon EventBridge event, including scheduled events.
 *
 * @see https://docs.aws.amazon.com/eventbridge/latest/userguide/eb-events-structure.html
 */
readonly class EventBridgeEvent
{
    /**
     * @param list<string> $resources
     * @param array<mixed> $detail
     */
    public function __construct(
        public string $version,
        public string $id,
        public string $detailType,
        public string $source,
        public string $account,
        public DateTimeImmutable $time,
        public string $region,
        public array $resources,
        public array $detail,
    ) {
    }

    /**
     * @throws TransformationException If the event is not an EventBridge event
     */
    public static function fromEvent(Event $event): self
    {
        $detailType = $event['detail-type'] ?? null;
        $source = $event['source'] ?? null;
        $detail = $event['detail'] ?? null;
        if (!is_string($detailType) || !is_string($source) || !is_array($detail)) {
            throw new TransformationException('EventBridge event must have "detail-type", "source" and "detail"', 'eventbridge');
        }

        $time = $event['time'] ?? null;
        try {
            $time = new DateTimeImmutable(is_string($time) ? $time : throw new Exception('"time" is missing'));
        } catch (Exception $e) {
            throw new TransformationException('EventBridge event has an invalid "time": ' . $e->getMessage(), 'eventbridge', 0, $e);
        }

        $resources = [];
        foreach ((array) ($event['resources'] ?? []) as $resource) {
            if (is_string($resource)) {
                $resources[] = $resource;
            }
        }

        return new self(
            version: self::string($event, 'version'),
            id: self::string($event, 'id'),
            detailType: $detailType,
            source: $source,
            account: self::string($event, 'account'),
            time: $time,
            region: self::string($event, 'region'),
            resources: $resources,
            detail: $detail,
        );
    }

    private static function string(Event $event, string $key): string
    {
        $value = $event[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
