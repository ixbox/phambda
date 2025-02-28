# PHP Runtime for AWS Lambda

## 概要

Phambda は、PHP で AWS Lambda を簡単に利用するためのランタイムを提供します。このパッケージを使用することで、AWS Lambda 上で PHP コードを実行するための設定や実装が簡素化されます。

### 主な特徴

- PSR-7 および PSR-15 に準拠したリクエスト/レスポンス処理
- AWS Lambda 環境に最適化されたランタイム
- シンプルなインターフェースでの実装

## インストール方法

以下のコマンドを実行してインストールします：

```console
composer require ixbox/phambda
```

### 必要な環境

- PHP 8.1 以上
- Composer

## 使用方法

### 基本的な Hello World の例

以下は、AWS Lambda 上で動作する簡単な Hello World の例です：

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Phambda\Http\Runtime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(body: "Hello World!");
    }
};
$runtime = new Runtime($handler);
$runtime->run();
```

### 実践的なユースケース例

以下は、リクエストの内容を処理し、JSON レスポンスを返す例です：

```php
$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        $data = ['message' => 'Hello, ' . $request->getHeaderLine('name')];
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
    }
};
$runtime = new Runtime($handler);
$runtime->run();
```

## ライセンス

このプロジェクトは MIT ライセンスの下で提供されています。

## コントリビューション

コントリビューションは歓迎します！バグ報告や機能提案は、GitHub の Issue を通じて行ってください。
