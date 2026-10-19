<?php

declare(strict_types=1);

namespace Phambda\Http;

use Phambda\Event;
use Phambda\Exception\TransformationException;

enum EventFormat: string
{
    case V2 = '2.0';
    case V1 = '1.0';
    case Alb = 'alb';
    case AlbMultiValue = 'alb-multi-value';

    public static function fromEvent(Event $event): self
    {
        $requestContext = $event['requestContext'] ?? null;

        if (is_array($requestContext) && is_array($requestContext['elb'] ?? null)) {
            return is_array($event['multiValueHeaders'] ?? null) ? self::AlbMultiValue : self::Alb;
        }

        $http = is_array($requestContext) ? ($requestContext['http'] ?? null) : null;
        if (($event['version'] ?? null) === self::V2->value || is_array($http)) {
            return self::V2;
        }

        if (is_string($event['httpMethod'] ?? null)) {
            return self::V1;
        }

        throw TransformationException::forRequest('HTTP event format is not recognized');
    }

    public function isAlb(): bool
    {
        return $this === self::Alb || $this === self::AlbMultiValue;
    }
}
