<?php

declare(strict_types=1);

namespace Phambda\S3;

use Phambda\Context;
use Phambda\Event;
use Phambda\HandlerInterface;

/**
 * Handles an Amazon S3 event notification that invokes the function directly.
 *
 * Each record is passed to handleRecord(). Throwing fails the invocation, so Lambda retries
 * it according to the function's asynchronous invocation settings. The "s3:TestEvent"
 * message is ignored.
 *
 * For notifications delivered through Amazon SQS, use SqsHandler and S3Event::fromJson()
 * on the message body instead.
 */
abstract class S3Handler implements HandlerInterface
{
    abstract protected function handleRecord(S3Record $record, Context $context): void;

    final public function handle(Event $event, Context $context): string
    {
        foreach (S3Event::fromEvent($event)->records as $record) {
            $this->handleRecord($record, $context);
        }

        return 'null';
    }
}
