<?php

declare(strict_types=1);

namespace Phambda\Sqs;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * One message of an Amazon SQS event.
 *
 * @see https://docs.aws.amazon.com/lambda/latest/dg/with-sqs.html
 */
readonly class SqsRecord
{
    /**
     * @param array<string, string> $attributes System attributes such as ApproximateReceiveCount
     * @param array<string, array<string, mixed>> $messageAttributes Message attributes as sent by Lambda
     */
    public function __construct(
        public string $messageId,
        public string $receiptHandle,
        public string $body,
        public array $attributes,
        public array $messageAttributes,
        public string $md5OfBody,
        public string $eventSourceArn,
        public string $awsRegion,
    ) {
    }

    /**
     * Decode the body as JSON (objects become associative arrays).
     *
     * @throws JsonException If the body is not valid JSON
     */
    public function json(): mixed
    {
        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * How many times the message has been received, including this time.
     */
    public function receiveCount(): int
    {
        return (int) ($this->attributes['ApproximateReceiveCount'] ?? 0);
    }

    public function sentAt(): ?DateTimeImmutable
    {
        $milliseconds = $this->attributes['SentTimestamp'] ?? null;
        if ($milliseconds === null || !ctype_digit($milliseconds)) {
            return null;
        }

        $sentAt = DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', intdiv((int) $milliseconds, 1000), (int) $milliseconds % 1000), new DateTimeZone('UTC'));

        return $sentAt === false ? null : $sentAt;
    }

    /**
     * The message group of a FIFO queue message, or null for standard queues.
     */
    public function messageGroupId(): ?string
    {
        return $this->attributes['MessageGroupId'] ?? null;
    }

    /**
     * Whether the message comes from a FIFO queue (FIFO queue names end with ".fifo").
     */
    public function isFifo(): bool
    {
        return str_ends_with($this->eventSourceArn, '.fifo');
    }

    /**
     * The string value of a message attribute, or null if it is missing or not a string.
     */
    public function messageAttribute(string $name): ?string
    {
        $value = $this->messageAttributes[$name]['stringValue'] ?? null;

        return is_string($value) ? $value : null;
    }
}
