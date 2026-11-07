<?php

declare(strict_types=1);

namespace Phambda\S3;

use DateTimeImmutable;

/**
 * One record of an Amazon S3 event notification.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html
 */
readonly class S3Record
{
    /**
     * @param string $key The object key, URL-decoded ("red+flower.jpg" in the notification becomes "red flower.jpg")
     * @param string $encodedKey The object key as it appears in the notification (URL-encoded)
     * @param ?int $size Object size in bytes. Not present for some events, such as deletions
     * @param array<mixed> $record The whole record, for fields not exposed as properties
     */
    public function __construct(
        public string $eventVersion,
        public string $eventName,
        public DateTimeImmutable $eventTime,
        public string $awsRegion,
        public string $bucketName,
        public string $bucketArn,
        public string $key,
        public string $encodedKey,
        public ?int $size,
        public ?string $eTag,
        public ?string $versionId,
        public ?string $sequencer,
        public array $record,
    ) {
    }

    /**
     * Whether the event is an ObjectCreated:* event (Put, Post, Copy, CompleteMultipartUpload).
     */
    public function isObjectCreated(): bool
    {
        return str_starts_with($this->eventName, 'ObjectCreated:');
    }

    /**
     * Whether the event is an ObjectRemoved:* event (Delete, DeleteMarkerCreated).
     */
    public function isObjectRemoved(): bool
    {
        return str_starts_with($this->eventName, 'ObjectRemoved:');
    }
}
