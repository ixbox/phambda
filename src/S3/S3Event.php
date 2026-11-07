<?php

declare(strict_types=1);

namespace Phambda\S3;

use DateTimeImmutable;
use Exception;
use JsonException;
use Phambda\Event;
use Phambda\Exception\TransformationException;

/**
 * An Amazon S3 event notification.
 *
 * Use fromEvent() when S3 invokes the function directly, and fromJson() when the
 * notification arrives through Amazon SQS or Amazon SNS as a message body.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html
 */
readonly class S3Event
{
    /** The event structure major version this class understands. */
    private const SUPPORTED_MAJOR_VERSION = '2';

    /**
     * @param list<S3Record> $records Empty for a test event
     * @param bool $isTestEvent Whether this is the "s3:TestEvent" message S3 sends after a notification is configured
     */
    public function __construct(
        public array $records,
        public bool $isTestEvent = false,
    ) {
    }

    /**
     * @throws TransformationException If the event is not an S3 event notification
     */
    public static function fromEvent(Event $event): self
    {
        return self::fromArray($event->jsonSerialize());
    }

    /**
     * Parse a notification delivered as a JSON string, such as an SQS or SNS message body.
     *
     * @throws TransformationException If the string is not an S3 event notification
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TransformationException('S3 event notification is not valid JSON: ' . $e->getMessage(), 's3', 0, $e);
        }

        if (!is_array($data)) {
            throw new TransformationException('S3 event notification must be a JSON object', 's3');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    private static function fromArray(array $data): self
    {
        if (($data['Event'] ?? null) === 's3:TestEvent') {
            return new self([], true);
        }

        $records = $data['Records'] ?? null;
        if (!is_array($records) || !array_is_list($records)) {
            throw new TransformationException('S3 event notification must have a "Records" list', 's3');
        }

        return new self(array_map(self::record(...), $records));
    }

    private static function record(mixed $record): S3Record
    {
        if (!is_array($record) || ($record['eventSource'] ?? null) !== 'aws:s3') {
            throw new TransformationException('S3 event record must have "eventSource": "aws:s3"', 's3');
        }

        $eventVersion = self::string($record, 'eventVersion');
        if (explode('.', $eventVersion)[0] !== self::SUPPORTED_MAJOR_VERSION) {
            throw new TransformationException("Unsupported S3 event version \"{$eventVersion}\"", 's3');
        }

        $s3 = is_array($record['s3'] ?? null) ? $record['s3'] : [];
        $bucket = is_array($s3['bucket'] ?? null) ? $s3['bucket'] : [];
        $object = is_array($s3['object'] ?? null) ? $s3['object'] : [];

        $encodedKey = $object['key'] ?? null;
        if (!is_string($encodedKey) || $encodedKey === '') {
            throw new TransformationException('S3 event record is missing "s3.object.key"', 's3');
        }

        try {
            $eventTime = new DateTimeImmutable(self::string($record, 'eventTime') ?: throw new Exception('"eventTime" is missing'));
        } catch (Exception $e) {
            throw new TransformationException('S3 event record has an invalid "eventTime": ' . $e->getMessage(), 's3', 0, $e);
        }

        $size = $object['size'] ?? null;

        return new S3Record(
            eventVersion: $eventVersion,
            eventName: self::string($record, 'eventName'),
            eventTime: $eventTime,
            awsRegion: self::string($record, 'awsRegion'),
            bucketName: self::string($bucket, 'name'),
            bucketArn: self::string($bucket, 'arn'),
            // Keys are form-urlencoded: "+" is a space
            key: urldecode($encodedKey),
            encodedKey: $encodedKey,
            size: is_int($size) ? $size : null,
            eTag: self::nullableString($object, 'eTag'),
            versionId: self::nullableString($object, 'versionId'),
            sequencer: self::nullableString($object, 'sequencer'),
            record: $record,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
