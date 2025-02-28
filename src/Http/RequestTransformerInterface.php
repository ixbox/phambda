<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Context;
use Phambda\Event;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for transformers that convert Lambda events to HTTP requests.
 */
interface RequestTransformerInterface extends TransformerInterface
{
    /**
     * Transform a Lambda event into a PSR-7 ServerRequest.
     *
     * @param Event $event The Lambda event
     * @param Context $context The Lambda context
     * @return ServerRequestInterface The transformed server request
     */
    public function transform(mixed $event, mixed $context = null): ServerRequestInterface;
}
