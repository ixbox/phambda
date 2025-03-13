<?php

use Phambda\Logger\NanoLogger;
use Psr\Log\LogLevel;

beforeEach(function () {
    putenv('TZ=UTC');
});

it('outputs log message in correct JSON format', function () {
    $logger = new NanoLogger(true);
    $message = 'Test message';
    $context = ['key' => 'value'];

    $output = '';
    $errorLogCallback = function ($msg) use (&$output) {
        $output = $msg;
        return true;
    };
    set_error_handler($errorLogCallback, E_USER_NOTICE);

    $logger->info($message, $context);

    restore_error_handler();

    $logData = json_decode($output, true);
    expect($logData)->toHaveKeys(['time', 'level', 'message', 'context']);
    expect($logData['level'])->toBe(LogLevel::INFO);
    expect($logData['message'])->toBe($message);
    expect($logData['context'])->toBe($context);
    expect($logData['time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');
});

it('filters debug messages when debug is disabled', function () {
    $logger = new NanoLogger(false);
    $output = '';
    $errorLogCallback = function ($msg) use (&$output) {
        $output = $msg;
        return true;
    };
    set_error_handler($errorLogCallback, E_USER_NOTICE);

    $logger->debug('This debug message should be filtered');
    expect($output)->toBe('');

    $logger->info('This info message should be output');
    $logData = json_decode($output, true);
    expect($logData['level'])->toBe(LogLevel::INFO);

    restore_error_handler();
});

it('outputs debug messages when debug is enabled', function () {
    $logger = new NanoLogger(true);
    $output = '';
    $errorLogCallback = function ($msg) use (&$output) {
        $output = $msg;
        return true;
    };
    set_error_handler($errorLogCallback, E_USER_NOTICE);

    $logger->debug('This debug message should be output');
    $logData = json_decode($output, true);
    expect($logData['level'])->toBe(LogLevel::DEBUG);

    restore_error_handler();
});
