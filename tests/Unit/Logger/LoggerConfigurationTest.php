<?php

use Phambda\Logger\LoggerConfiguration;

// Do not depend on, or leave behind, the time zone set by other tests
beforeEach(function (): void {
    putenv('LOG_TIMEZONE');
});

afterEach(function (): void {
    putenv('LOG_TIMEZONE');
});

test('デフォルトではUTCタイムゾーンを使用する', function (): void {
    $config = new LoggerConfiguration();
    expect($config->getTimezone()->getName())->toBe('UTC');
});

test('コンストラクタで指定したタイムゾーンを使用する', function (): void {
    $config = new LoggerConfiguration('Asia/Tokyo');
    expect($config->getTimezone()->getName())->toBe('Asia/Tokyo');
});

test('環境変数からタイムゾーンを取得する', function (): void {
    putenv('LOG_TIMEZONE=Europe/London');
    $config = new LoggerConfiguration();
    expect($config->getTimezone()->getName())->toBe('Europe/London');
});

test('デフォルトではRFC3339_EXTENDED形式を使用する', function (): void {
    $config = new LoggerConfiguration();
    expect($config->getDateFormat())->toBe(DateTimeInterface::RFC3339_EXTENDED);
});

test('カスタムの日付フォーマットを使用できる', function (): void {
    $customFormat = 'Y-m-d H:i:s.u T';
    $config = new LoggerConfiguration(null, $customFormat);
    expect($config->getDateFormat())->toBe($customFormat);
});
