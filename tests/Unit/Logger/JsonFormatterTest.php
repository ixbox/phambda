<?php

use Phambda\Logger\JsonFormatter;

it('formats data correctly', function () {
    $formatter = new JsonFormatter();
    $data = [
        'time' => 'test_time',
        'level' => 'test_level',
        'message' => 'test_message',
        'context' => ['key' => 'value'],
    ];
    $expected = json_encode($data);

    expect($formatter->format($data))->toBe($expected);
});
