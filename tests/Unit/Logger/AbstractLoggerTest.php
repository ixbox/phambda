<?php

use Phambda\Logger\AbstractLogger;
use Phambda\Logger\LogFormatterInterface;
use Phambda\Logger\LoggerConfiguration;
use Psr\Log\LogLevel;

beforeEach(function () {
    putenv('LOG_TIMEZONE');
});

it('filters log levels correctly', function () {
    $formatter = Mockery::mock(LogFormatterInterface::class);
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

it('uses environment timezone when no configuration is provided', function () {
    putenv('LOG_TIMEZONE=Asia/Tokyo');

    $formatter = Mockery::mock(LogFormatterInterface::class);
    $formatter->shouldReceive('format')
        ->once()
        ->with(Mockery::on(function ($data) {
            return strpos($data['time'], '+09:00') !== false;
        }))
        ->andReturn('');

    $logger = new class($formatter, LogLevel::DEBUG) extends AbstractLogger {
        public function __construct($formatter, $minimumLevel)
        {
            parent::__construct($formatter, $minimumLevel);
        }
    };

    $logger->info('Test message');
});

it('prioritizes configuration timezone over environment variable', function () {
    putenv('LOG_TIMEZONE=America/New_York');  // UTC-4

    $formatter = Mockery::mock(LogFormatterInterface::class);
    $formatter->shouldReceive('format')
        ->once()
        ->with(Mockery::on(function ($data) {
            return strpos($data['time'], '+09:00') !== false;  // Asia/Tokyo
        }))
        ->andReturn('');

    $config = new LoggerConfiguration('Asia/Tokyo');
    $logger = new class($formatter, LogLevel::DEBUG, [], $config) extends AbstractLogger {
        public function __construct($formatter, $minimumLevel, $defaultContext, $config)
        {
            parent::__construct($formatter, $minimumLevel, $defaultContext, $config);
        }
    };

    $logger->info('Test message');
});

it('uses UTC timezone when no configuration or environment variable is provided', function () {
    $formatter = Mockery::mock(LogFormatterInterface::class);
    $formatter->shouldReceive('format')
        ->once()
        ->with(Mockery::on(function ($data) {
            return strpos($data['time'], '+00:00') !== false;
        }))
        ->andReturn('');

    $logger = new class($formatter, LogLevel::DEBUG) extends AbstractLogger {
        public function __construct($formatter, $minimumLevel)
        {
            parent::__construct($formatter, $minimumLevel);
        }
    };

    $logger->info('Test message');
});

it('uses correct timezone from configuration', function () {
    $formatter = Mockery::mock(LogFormatterInterface::class);
    $formatter->shouldReceive('format')
        ->once()
        ->with(Mockery::on(function ($data) {
            return strpos($data['time'], '+09:00') !== false;
        }))
        ->andReturn('');

    $config = new LoggerConfiguration('Asia/Tokyo');
    $logger = new class($formatter, LogLevel::DEBUG, [], $config) extends AbstractLogger {
        public function __construct($formatter, $minimumLevel, $defaultContext, $config)
        {
            parent::__construct($formatter, $minimumLevel, $defaultContext, $config);
        }
    };

    $logger->info('Test message');
});
