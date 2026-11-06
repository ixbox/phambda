<?php

declare(strict_types=1);

namespace Phambda\Sqs;

use Phambda\Context;
use Phambda\Event;
use Phambda\HandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Handles an Amazon SQS batch one message at a time.
 *
 * Implement handleRecord(). Throwing from it marks that message as failed.
 *
 * Partial batch responses are off by default: any failure fails the whole batch, so
 * every message in it becomes visible again (messages may be processed twice, but none
 * is lost). Pass reportBatchItemFailures: true only when the event source mapping has
 * "ReportBatchItemFailures" enabled. Otherwise Lambda ignores the response, treats the
 * batch as successful, and deletes the failed messages too.
 *
 * With partial batch responses on a FIFO queue, processing stops at the first failure and
 * the remaining messages are reported as failed as well, to keep their order.
 *
 * @see https://docs.aws.amazon.com/lambda/latest/dg/services-sqs-errorhandling.html
 */
abstract class SqsHandler implements HandlerInterface
{
    public function __construct(
        private readonly bool $reportBatchItemFailures = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Process one message. Throw to mark it as failed.
     */
    abstract protected function handleRecord(SqsRecord $record, Context $context): void;

    final public function handle(Event $event, Context $context): string
    {
        $failures = [];
        $stopped = false;

        foreach (SqsEvent::fromEvent($event)->records as $record) {
            if ($stopped) {
                $failures[] = $record->messageId;
                continue;
            }

            try {
                $this->handleRecord($record, $context);
            } catch (Throwable $error) {
                if (!$this->reportBatchItemFailures) {
                    throw $error;
                }

                $this->logger->error('Failed to process SQS message', [
                    'message_id' => $record->messageId,
                    'error' => $error->getMessage(),
                    'type' => $error::class,
                ]);
                $failures[] = $record->messageId;
                $stopped = $record->isFifo();
            }
        }

        return json_encode([
            'batchItemFailures' => array_map(
                static fn (string $messageId): array => ['itemIdentifier' => $messageId],
                $failures,
            ),
        ], JSON_THROW_ON_ERROR);
    }
}
