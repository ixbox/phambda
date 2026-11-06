<?php

declare(strict_types=1);

namespace Phambda\EventBridge;

use Phambda\Context;
use Phambda\Event;
use Phambda\HandlerInterface;

/**
 * Handles an Amazon EventBridge event, including scheduled events.
 *
 * Throwing from handleEvent() fails the invocation, so Lambda retries it according to
 * the function's asynchronous invocation settings.
 */
abstract class EventBridgeHandler implements HandlerInterface
{
    abstract protected function handleEvent(EventBridgeEvent $event, Context $context): void;

    final public function handle(Event $event, Context $context): string
    {
        $this->handleEvent(EventBridgeEvent::fromEvent($event), $context);

        return 'null';
    }
}
