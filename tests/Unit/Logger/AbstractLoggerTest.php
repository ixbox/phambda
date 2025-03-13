<?php

use Phambda\Logger\AbstractLogger;
use Phambda\Logger\LogFormatterInterface;
use Psr\Log\LogLevel;

it('filters log levels correctly', function () {
    $formatter = Mockery::mock(\Phambda\Logger\LogFormatterInterface::class);
    $formatter->shouldReceive('format')
        ->once()
        ->with(Mockery::on(function ($data) {
            return $data['level'] === LogLevel::INFO;
        }))
        ->andReturn('');

    $logger = new class($formatter, LogLevel::INFO) extends AbstractLogger {
        public function __construct($formatter, $minimumLevel)
        {
            parent::__construct($formatter, $minimumLevel);
        }
    };

    $logger->debug('This is a debug message');
    $logger->info('This is an info message');
});
