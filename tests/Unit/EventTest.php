<?php

declare(strict_types=1);

namespace Tests\Unit;

use Phambda\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testIssetFollowsPhpSemantics(): void
    {
        $event = new Event([
            'pathParameters' => null,
            'body' => '',
        ]);

        // Like isset() on an array: a null value is not set
        $this->assertFalse(isset($event['pathParameters']));
        $this->assertNull($event['pathParameters']);
        $this->assertTrue(isset($event['body']));
        $this->assertFalse(isset($event['missing']));
    }

    public function testHasReportsKeysWithNullValues(): void
    {
        $event = new Event([
            'pathParameters' => null,
        ]);

        $this->assertTrue($event->has('pathParameters'));
        $this->assertFalse($event->has('missing'));
    }
}
