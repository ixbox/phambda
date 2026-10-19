<?php

namespace Tests\Unit\Logger;

use Phambda\Logger\LtsvFormatter;

it('formats data correctly', function (): void {
    $formatter = new LtsvFormatter();
    $data = [
        'time' => 'test_time',
        'level' => 'test_level',
        'message' => 'test_message',
        'context' => ['key' => 'value'],
    ];
    $expected = "time:test_time\tlevel:test_level\tmessage:test_message\tcontext:{\"key\":\"value\"}";

    expect($formatter->format($data))->toBe($expected);
});

it('formats null, booleans and objects without failing', function (): void {
    $formatter = new LtsvFormatter();

    expect($formatter->format([
        'null' => null,
        'true' => true,
        'object' => new \ArrayObject(['a' => 1]),
        'invalid' => ["\xff"],
    ]))->toBe("null:\ttrue:1\tobject:{\"a\":1}\tinvalid:[\"\u{FFFD}\"]");
});
