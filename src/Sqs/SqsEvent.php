<?php

declare(strict_types=1);

namespace Phambda\Sqs;

use Phambda\Event;
use Phambda\Exception\TransformationException;

/**
 * A batch of Amazon SQS messages.
 *
 * @see https://docs.aws.amazon.com/lambda/latest/dg/with-sqs.html
 */
readonly class SqsEvent
{
    /**
     * @param list<SqsRecord> $records
     */
    public function __construct(
        public array $records,
    ) {
    }

    /**
     * @throws TransformationException If the event is not an SQS event
     */
    public static function fromEvent(Event $event): self
    {
        $records = $event['Records'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new TransformationException('SQS event must have a "Records" list', 'sqs');
        }

        return new self(array_map(self::record(...), $records));
    }

    private static function record(mixed $record): SqsRecord
    {
        if (!is_array($record) || ($record['eventSource'] ?? null) !== 'aws:sqs') {
            throw new TransformationException('SQS event record must have "eventSource": "aws:sqs"', 'sqs');
        }

        $messageId = $record['messageId'] ?? null;
        if (!is_string($messageId) || $messageId === '') {
            throw new TransformationException('SQS event record is missing "messageId"', 'sqs');
        }

        $attributes = [];
        foreach (self::map($record['attributes'] ?? null) as $name => $value) {
            if (is_string($value)) {
                $attributes[$name] = $value;
            }
        }

        $messageAttributes = [];
        foreach (self::map($record['messageAttributes'] ?? null) as $name => $value) {
            if (is_array($value)) {
                $messageAttributes[$name] = self::map($value);
            }
        }

        return new SqsRecord(
            messageId: $messageId,
            receiptHandle: self::string($record, 'receiptHandle'),
            body: self::string($record, 'body'),
            attributes: $attributes,
            messageAttributes: $messageAttributes,
            md5OfBody: self::string($record, 'md5OfBody'),
            eventSourceArn: self::string($record, 'eventSourceARN'),
            awsRegion: self::string($record, 'awsRegion'),
        );
    }

    /**
     * @param array<mixed> $record
     */
    private static function string(array $record, string $key): string
    {
        $value = $record[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
