<?php

use Phambda\Logger\LoggerConfiguration;

test('デフォルトではUTCタイムゾーンを使用する', function () {
    $config = new LoggerConfiguration();
    expect($config->getTimezone()->getName())->toBe('UTC');
});

test('コンストラクタで指定したタイムゾーンを使用する', function () {
    $config = new LoggerConfiguration('Asia/Tokyo');
    expect($config->getTimezone()->getName())->toBe('Asia/Tokyo');
});

test('環境変数からタイムゾーンを取得する', function () {
    putenv('LOG_TIMEZONE=Europe/London');
    $config = new LoggerConfiguration();
    expect($config->getTimezone()->getName())->toBe('Europe/London');
    putenv('LOG_TIMEZONE'); // 環境変数をクリア
});

test('デフォルトではRFC3339_EXTENDED形式を使用する', function () {
    $config = new LoggerConfiguration();
    expect($config->getDateFormat())->toBe(DateTimeInterface::RFC3339_EXTENDED);
});

test('カスタムの日付フォーマットを使用できる', function () {
    $customFormat = 'Y-m-d H:i:s.u T';
    $config = new LoggerConfiguration(null, $customFormat);
    expect($config->getDateFormat())->toBe($customFormat);
});
