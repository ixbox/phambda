<?php

use Phambda\Logger\JsonFormatter;
use Phambda\Logger\LoggerConfiguration;

// Other tests leave the identifiers of the invocation they received in the environment
beforeEach(function (): void {
    putenv('PHAMBDA_AWS_REQUEST_ID');
    putenv('_X_AMZN_TRACE_ID');
});

it('formats data correctly', function (): void {
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

it('adds the request ID and the trace header of the current invocation', function (): void {
    putenv('PHAMBDA_AWS_REQUEST_ID=8476a536-e9f4-11e8-9739-2dfe598c3fcd');
    putenv('_X_AMZN_TRACE_ID=Root=1-5759e988-bd862e3fe1be46a994272793;Parent=557abcec3ee5a047;Sampled=1');

    try {
        $decoded = json_decode((new JsonFormatter())->format(['message' => 'test']), true);
    } finally {
        putenv('PHAMBDA_AWS_REQUEST_ID');
        putenv('_X_AMZN_TRACE_ID');
    }

    expect($decoded['request_id'])->toBe('8476a536-e9f4-11e8-9739-2dfe598c3fcd')
        ->and($decoded['trace_id'])->toBe('Root=1-5759e988-bd862e3fe1be46a994272793;Parent=557abcec3ee5a047;Sampled=1');
});

it('omits the request ID and the trace header outside an invocation', function (): void {
    $decoded = json_decode((new JsonFormatter())->format(['message' => 'test']), true);

    expect($decoded)->not->toHaveKey('request_id')
        ->and($decoded)->not->toHaveKey('trace_id');
});

it('omits the request ID and the trace header when disabled', function (): void {
    putenv('PHAMBDA_AWS_REQUEST_ID=8476a536-e9f4-11e8-9739-2dfe598c3fcd');
    putenv('_X_AMZN_TRACE_ID=Root=1-5759e988-bd862e3fe1be46a994272793');
    $config = new LoggerConfiguration(contextFlags: [
        'lambda_context' => true,
        'request_id' => false,
        'memory_usage' => false,
    ]);

    try {
        $decoded = json_decode((new JsonFormatter($config))->format(['message' => 'test']), true);
    } finally {
        putenv('PHAMBDA_AWS_REQUEST_ID');
        putenv('_X_AMZN_TRACE_ID');
    }

    expect($decoded)->not->toHaveKey('request_id')
        ->and($decoded)->not->toHaveKey('trace_id');
});
