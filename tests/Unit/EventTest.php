<?php

declare(strict_types=1);

namespace Tests\Unit;

use Phambda\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    public function testNullValueKeyIsPresent(): void
    {
        $event = new Event([
            'pathParameters' => null,
        ]);

        $this->assertTrue(isset($event['pathParameters']));
        $this->assertNull($event['pathParameters']);
        $this->assertFalse(isset($event['missing']));
    }
}
