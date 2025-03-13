<?php

namespace Tests\Unit\Logger;

use Phambda\Logger\LtsvFormatter;

it('formats data correctly', function () {
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
